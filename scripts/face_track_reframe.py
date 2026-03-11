#!/usr/bin/env python3
"""
Face-tracking reframer for portrait short videos.

Input: any landscape/portrait clip
Output: portrait clip (video-only) with smooth face-centered crop
"""

import argparse
import json
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

    fourcc = cv2.VideoWriter_fourcc(*"mp4v")
    writer = cv2.VideoWriter(out_path, fourcc, fps, (out_w, out_h))
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
                fx, fy, fw, fh = max(faces, key=lambda r: r[2] * r[3])
                if detect_scale < 0.99:
                    fx = fx / detect_scale
                    fy = fy / detect_scale
                    fw = fw / detect_scale
                    fh = fh / detect_scale
                cx = fx + (fw / 2.0)
                cy = fy + (fh / 2.0)
                desired_x = clamp(cx - (crop_w / 2.0), 0.0, float(src_w - crop_w))
                # Bias face to upper-mid area for Shorts composition.
                desired_y = clamp(cy - (crop_h * 0.38), 0.0, float(src_h - crop_h))

        x = (smooth * x) + ((1.0 - smooth) * desired_x)
        y = (smooth * y) + ((1.0 - smooth) * desired_y)
        xi = int(round(clamp(x, 0.0, float(src_w - crop_w))))
        yi = int(round(clamp(y, 0.0, float(src_h - crop_h))))

        crop = frame[yi : yi + crop_h, xi : xi + crop_w]
        if crop.size == 0:
            frames += 1
            continue

        reframed = cv2.resize(crop, (out_w, out_h), interpolation=cv2.INTER_AREA)
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
                "output": out_path,
            }
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
