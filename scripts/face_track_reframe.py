#!/usr/bin/env python3
"""
Face-tracking reframer for portrait short videos.

Input: any landscape/portrait clip
Output: portrait clip (video-only) with smooth face-centered crop
"""

import argparse
import json
import math
import os
import sys


def clamp(value, low, high):
    return max(low, min(high, value))


def main():
    parser = argparse.ArgumentParser(description="Smooth face tracking reframer")
    parser.add_argument("--input", required=True, help="Input video path")
    parser.add_argument("--output", required=True, help="Output video path")
    parser.add_argument("--width", type=int, default=1080, help="Output width")
    parser.add_argument("--height", type=int, default=1920, help="Output height")
    parser.add_argument("--smooth", type=float, default=0.88, help="Smoothing factor [0.5..0.98]")
    parser.add_argument("--detect-scale", type=float, default=0.5, help="Face detection resize scale [0.2..1.0]")
    parser.add_argument("--detect-interval", type=int, default=3, help="Run detector every N frames")
    args = parser.parse_args()

    # Keep tracker low-resource on small VPS/shared hosting.
    os.environ.setdefault("OMP_NUM_THREADS", "1")
    os.environ.setdefault("OPENBLAS_NUM_THREADS", "1")
    os.environ.setdefault("MKL_NUM_THREADS", "1")
    os.environ.setdefault("NUMEXPR_NUM_THREADS", "1")
    os.environ.setdefault("VECLIB_MAXIMUM_THREADS", "1")
    os.environ.setdefault("OPENCV_FOR_THREADS_NUM", "1")
    os.environ.setdefault("OPENCV_OPENCL_RUNTIME", "disabled")

    try:
        import cv2
    except Exception as exc:
        print(f"OpenCV import failed: {exc}", file=sys.stderr)
        return 2

    try:
        cv2.setNumThreads(1)
    except Exception:
        pass
    try:
        cv2.setUseOptimized(False)
    except Exception:
        pass
    try:
        cv2.ocl.setUseOpenCL(False)
    except Exception:
        pass

    in_path = args.input
    out_path = args.output
    out_w = int(args.width)
    out_h = int(args.height)
    smooth = clamp(float(args.smooth), 0.5, 0.98)
    detect_scale = clamp(float(args.detect_scale), 0.2, 1.0)
    detect_interval = max(1, int(args.detect_interval))

    if not os.path.isfile(in_path):
        print("Input file not found", file=sys.stderr)
        return 3

    cap = cv2.VideoCapture(in_path)
    if not cap.isOpened():
        print("Failed to open input video", file=sys.stderr)
        return 4

    fps = cap.get(cv2.CAP_PROP_FPS)
    if fps is None or fps <= 0:
        fps = 25.0

    src_w = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
    src_h = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
    if src_w <= 0 or src_h <= 0:
        cap.release()
        print("Invalid input dimensions", file=sys.stderr)
        return 5

    target_aspect = out_w / float(out_h)
    src_aspect = src_w / float(src_h)

    if src_aspect > target_aspect:
        crop_w = int(src_h * target_aspect)
        crop_h = src_h
    else:
        crop_w = src_w
        crop_h = int(src_w / target_aspect)

    crop_w = clamp(crop_w, 2, src_w)
    crop_h = clamp(crop_h, 2, src_h)

    writer = cv2.VideoWriter(out_path, cv2.VideoWriter_fourcc(*"MJPG"), fps, (out_w, out_h))
    writer_codec = "MJPG"
    if not writer.isOpened():
        writer = cv2.VideoWriter(out_path, cv2.VideoWriter_fourcc(*"mp4v"), fps, (out_w, out_h))
        writer_codec = "mp4v"
    if not writer.isOpened():
        cap.release()
        print("Failed to open output video writer", file=sys.stderr)
        return 6

    cascade_path = cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
    face_cascade = cv2.CascadeClassifier(cascade_path)
    if face_cascade.empty():
        cap.release()
        writer.release()
        print("Failed to load Haar cascade model", file=sys.stderr)
        return 7

    x = (src_w - crop_w) / 2.0
    y = (src_h - crop_h) / 2.0
    frames = 0
    faces_detected = 0
    active_face = None
    prev_gray = None

    while True:
        ok, frame = cap.read()
        if not ok:
            break

        desired_x = x
        desired_y = y

        if frames % detect_interval == 0:
            proc = frame
            if detect_scale < 0.99:
                proc = cv2.resize(
                    frame,
                    (max(1, int(src_w * detect_scale)), max(1, int(src_h * detect_scale))),
                    interpolation=cv2.INTER_AREA,
                )

            gray = cv2.cvtColor(proc, cv2.COLOR_BGR2GRAY)
            min_size = max(20, int(min(gray.shape[1], gray.shape[0]) * 0.08))
            faces = face_cascade.detectMultiScale(
                gray,
                scaleFactor=1.1,
                minNeighbors=5,
                minSize=(min_size, min_size),
                flags=cv2.CASCADE_SCALE_IMAGE,
            )

            if len(faces) > 0:
                faces_detected += 1
                proc_h, proc_w = gray.shape[:2]
                scale_x = src_w / float(proc_w)
                scale_y = src_h / float(proc_h)

                best = None
                best_score = -1e9

                for (fx, fy, fw, fh) in faces:
                    cx = fx + (fw / 2.0)
                    cy = fy + (fh / 2.0)

                    # Face size score (prefer clear / dominant face).
                    area_score = (fw * fh) / float(max(1, proc_w * proc_h))
                    area_score = clamp(area_score * 10.0, 0.0, 1.5)

                    # Center score (help keep composition natural).
                    center_score = 1.0 - abs(cx - (proc_w / 2.0)) / float(max(1.0, proc_w / 2.0))

                    # Sticky tracking score (reduce random switching).
                    sticky_score = 0.0
                    if active_face is not None:
                        prev_cx, prev_cy = active_face
                        dist = math.hypot(cx - prev_cx, cy - prev_cy)
                        sticky_score = 1.0 - (dist / float(max(1.0, max(proc_w, proc_h) * 0.55)))
                        sticky_score = clamp(sticky_score, -0.5, 1.0)

                    # Speaking score from mouth motion.
                    speaking_score = 0.0
                    if prev_gray is not None:
                        mx1 = int(clamp(fx + (fw * 0.18), 0, proc_w - 1))
                        mx2 = int(clamp(fx + (fw * 0.82), 1, proc_w))
                        my1 = int(clamp(fy + (fh * 0.55), 0, proc_h - 1))
                        my2 = int(clamp(fy + (fh * 0.96), 1, proc_h))
                        if mx2 > mx1 and my2 > my1:
                            roi_now = gray[my1:my2, mx1:mx2]
                            roi_prev = prev_gray[my1:my2, mx1:mx2]
                            if roi_now.size > 0 and roi_now.shape == roi_prev.shape:
                                diff = cv2.absdiff(roi_now, roi_prev)
                                speaking_raw = float(diff.mean())
                                speaking_score = clamp(speaking_raw / 12.0, 0.0, 2.0)

                    total_score = (
                        speaking_score * 2.6
                        + area_score * 1.2
                        + sticky_score * 1.3
                        + center_score * 0.35
                    )

                    if total_score > best_score:
                        best_score = total_score
                        best = (fx, fy, fw, fh, cx, cy)

                if best is not None:
                    fx, fy, fw, fh, cx_proc, cy_proc = best
                    active_face = (cx_proc, cy_proc)
                    cx = (cx_proc * scale_x)
                    cy = (cy_proc * scale_y)
                else:
                    cx = src_w / 2.0
                    cy = src_h / 2.0

                desired_x = clamp(cx - (crop_w / 2.0), 0.0, float(src_w - crop_w))
                # Bias face to upper-mid area for Shorts composition.
                desired_y = clamp(cy - (crop_h * 0.38), 0.0, float(src_h - crop_h))

            prev_gray = gray
        elif prev_gray is None:
            # Keep previous gray initialized when detector is skipped.
            gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
            if detect_scale < 0.99:
                gray = cv2.resize(
                    gray,
                    (max(1, int(src_w * detect_scale)), max(1, int(src_h * detect_scale))),
                    interpolation=cv2.INTER_AREA,
                )
            prev_gray = gray
        # Deadzone to avoid micro jitter.
        if abs(desired_x - x) < 2.0:
            desired_x = x
        if abs(desired_y - y) < 2.0:
            desired_y = y

        # Adaptive smoothing: quick response for speaker switch, smooth for small movement.
        movement = math.hypot(desired_x - x, desired_y - y)
        if movement > (crop_w * 0.18):
            alpha = clamp(smooth - 0.22, 0.55, 0.92)
        elif movement > (crop_w * 0.07):
            alpha = clamp(smooth - 0.10, 0.60, 0.94)
        else:
            alpha = smooth

        next_x = (alpha * x) + ((1.0 - alpha) * desired_x)
        next_y = (alpha * y) + ((1.0 - alpha) * desired_y)

        max_step_x = max(3.0, crop_w * 0.09)
        max_step_y = max(3.0, crop_h * 0.09)
        if next_x > x + max_step_x:
            next_x = x + max_step_x
        elif next_x < x - max_step_x:
            next_x = x - max_step_x
        if next_y > y + max_step_y:
            next_y = y + max_step_y
        elif next_y < y - max_step_y:
            next_y = y - max_step_y

        x = next_x
        y = next_y
        xi = int(round(clamp(x, 0.0, float(src_w - crop_w))))
        yi = int(round(clamp(y, 0.0, float(src_h - crop_h))))

        crop = frame[yi : yi + crop_h, xi : xi + crop_w]
        if crop.size == 0:
            frames += 1
            continue

        reframed = cv2.resize(crop, (out_w, out_h), interpolation=cv2.INTER_CUBIC)
        writer.write(reframed)
        frames += 1

    cap.release()
    writer.release()

    if not os.path.isfile(out_path) or os.path.getsize(out_path) <= 0:
        print("Output video not created", file=sys.stderr)
        return 8

    print(
        json.dumps(
            {
                "frames": frames,
                "faces_detected": faces_detected,
                "smooth": smooth,
                "detect_scale": detect_scale,
                "detect_interval": detect_interval,
                "writer_codec": writer_codec,
                "output": out_path,
            }
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
