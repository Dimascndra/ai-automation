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
from statistics import median
import sys


def clamp(value, low, high):
    return max(low, min(high, value))


def seed_track_points(cv2, gray, fx, fy, fw, fh):
    proc_h, proc_w = gray.shape[:2]
    x1 = int(clamp(fx + (fw * 0.1), 0, proc_w - 2))
    y1 = int(clamp(fy + (fh * 0.1), 0, proc_h - 2))
    x2 = int(clamp(fx + (fw * 0.9), x1 + 1, proc_w - 1))
    y2 = int(clamp(fy + (fh * 0.9), y1 + 1, proc_h - 1))
    roi = gray[y1:y2, x1:x2]
    if roi.size == 0:
        return None
    points = cv2.goodFeaturesToTrack(
        roi,
        maxCorners=40,
        qualityLevel=0.02,
        minDistance=3,
        blockSize=3,
    )
    if points is None or len(points) == 0:
        return None
    points[:, 0, 0] += x1
    points[:, 0, 1] += y1
    return points


def dedupe_faces(faces):
    unique = []
    for fx, fy, fw, fh in faces:
        cx = fx + (fw / 2.0)
        cy = fy + (fh / 2.0)
        duplicate = False
        for ux, uy, uw, uh in unique:
            ucx = ux + (uw / 2.0)
            ucy = uy + (uh / 2.0)
            dist = math.hypot(cx - ucx, cy - ucy)
            size_ref = max(10.0, (fw + fh + uw + uh) / 4.0)
            if dist < (size_ref * 0.35):
                duplicate = True
                break
        if not duplicate:
            unique.append((fx, fy, fw, fh))
    return unique


def detect_faces(cv2, gray, frontal_cascade, profile_cascade, min_size):
    faces = []
    frontal = frontal_cascade.detectMultiScale(
        gray,
        scaleFactor=1.08,
        minNeighbors=5,
        minSize=(min_size, min_size),
        flags=cv2.CASCADE_SCALE_IMAGE,
    )
    for fx, fy, fw, fh in frontal:
        faces.append((int(fx), int(fy), int(fw), int(fh)))

    if profile_cascade is not None and not profile_cascade.empty():
        profile_left = profile_cascade.detectMultiScale(
            gray,
            scaleFactor=1.08,
            minNeighbors=5,
            minSize=(min_size, min_size),
            flags=cv2.CASCADE_SCALE_IMAGE,
        )
        for fx, fy, fw, fh in profile_left:
            faces.append((int(fx), int(fy), int(fw), int(fh)))

        flipped = cv2.flip(gray, 1)
        profile_right = profile_cascade.detectMultiScale(
            flipped,
            scaleFactor=1.08,
            minNeighbors=5,
            minSize=(min_size, min_size),
            flags=cv2.CASCADE_SCALE_IMAGE,
        )
        proc_w = gray.shape[1]
        for fx, fy, fw, fh in profile_right:
            mapped_x = int(proc_w - fx - fw)
            faces.append((mapped_x, int(fy), int(fw), int(fh)))

    return dedupe_faces(faces)


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

    frontal_path = cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
    frontal_cascade = cv2.CascadeClassifier(frontal_path)
    if frontal_cascade.empty():
        cap.release()
        writer.release()
        print("Failed to load Haar cascade model", file=sys.stderr)
        return 7

    profile_path = cv2.data.haarcascades + "haarcascade_profileface.xml"
    profile_cascade = cv2.CascadeClassifier(profile_path)
    if profile_cascade.empty():
        profile_cascade = None

    x = (src_w - crop_w) / 2.0
    y = (src_h - crop_h) / 2.0
    frames = 0
    faces_detected = 0
    active_face = None
    active_face_size = None
    track_points = None
    lost_face_frames = 0
    prev_gray = None

    while True:
        ok, frame = cap.read()
        if not ok:
            break

        desired_x = x
        desired_y = y

        proc = frame
        if detect_scale < 0.99:
            proc = cv2.resize(
                frame,
                (max(1, int(src_w * detect_scale)), max(1, int(src_h * detect_scale))),
                interpolation=cv2.INTER_AREA,
            )
        gray = cv2.cvtColor(proc, cv2.COLOR_BGR2GRAY)
        gray = cv2.equalizeHist(gray)

        proc_h, proc_w = gray.shape[:2]
        scale_x = src_w / float(proc_w)
        scale_y = src_h / float(proc_h)
        detected_this_frame = False

        if prev_gray is not None and track_points is not None and len(track_points) >= 6:
            next_pts, status, _ = cv2.calcOpticalFlowPyrLK(
                prev_gray,
                gray,
                track_points,
                None,
                winSize=(21, 21),
                maxLevel=2,
                criteria=(cv2.TERM_CRITERIA_EPS | cv2.TERM_CRITERIA_COUNT, 20, 0.03),
            )
            if next_pts is not None and status is not None:
                valid_mask = status.reshape(-1) == 1
                old_pts = track_points.reshape(-1, 2)[valid_mask]
                new_pts = next_pts.reshape(-1, 2)[valid_mask]
                if len(new_pts) >= 6:
                    dx_values = []
                    dy_values = []
                    for old_pt, new_pt in zip(old_pts, new_pts):
                        dx_values.append(float(new_pt[0] - old_pt[0]))
                        dy_values.append(float(new_pt[1] - old_pt[1]))
                    dx = median(dx_values)
                    dy = median(dy_values)

                    if active_face is not None:
                        active_face = (
                            clamp(active_face[0] + (dx * scale_x), 0.0, float(src_w)),
                            clamp(active_face[1] + (dy * scale_y), 0.0, float(src_h)),
                        )
                    else:
                        cx_proc = median([float(p[0]) for p in new_pts])
                        cy_proc = median([float(p[1]) for p in new_pts])
                        active_face = (
                            clamp(cx_proc * scale_x, 0.0, float(src_w)),
                            clamp(cy_proc * scale_y, 0.0, float(src_h)),
                        )
                    track_points = new_pts.reshape(-1, 1, 2)
                else:
                    track_points = None
            else:
                track_points = None

        if frames % detect_interval == 0:
            min_size = max(20, int(min(proc_w, proc_h) * 0.075))
            faces = detect_faces(cv2, gray, frontal_cascade, profile_cascade, min_size)
            if len(faces) > 0:
                faces_detected += 1
                detected_this_frame = True
                best = None
                best_score = -1e9

                for (fx, fy, fw, fh) in faces:
                    cx_proc = fx + (fw / 2.0)
                    cy_proc = fy + (fh / 2.0)

                    area_score = (fw * fh) / float(max(1, proc_w * proc_h))
                    area_score = clamp(area_score * 10.0, 0.0, 1.6)

                    center_score = 1.0 - abs(cx_proc - (proc_w / 2.0)) / float(max(1.0, proc_w / 2.0))
                    center_score = clamp(center_score, -0.3, 1.0)

                    sticky_score = 0.0
                    if active_face is not None:
                        prev_cx_proc = active_face[0] / float(scale_x)
                        prev_cy_proc = active_face[1] / float(scale_y)
                        dist = math.hypot(cx_proc - prev_cx_proc, cy_proc - prev_cy_proc)
                        sticky_score = 1.0 - (dist / float(max(1.0, max(proc_w, proc_h) * 0.55)))
                        sticky_score = clamp(sticky_score, -0.5, 1.0)

                    stability_score = 0.0
                    if active_face_size is not None:
                        prev_fw_proc = active_face_size[0] / float(scale_x)
                        prev_fh_proc = active_face_size[1] / float(scale_y)
                        wr = min(fw, prev_fw_proc) / float(max(1.0, max(fw, prev_fw_proc)))
                        hr = min(fh, prev_fh_proc) / float(max(1.0, max(fh, prev_fh_proc)))
                        stability_score = clamp(((wr + hr) / 2.0) * 1.6 - 0.6, -0.5, 1.0)

                    edge_margin = min(fx, fy, proc_w - (fx + fw), proc_h - (fy + fh))
                    edge_score = clamp(edge_margin / float(max(1.0, min(proc_w, proc_h) * 0.12)), -0.5, 1.0)

                    speaking_score = 0.0
                    if prev_gray is not None and prev_gray.shape == gray.shape:
                        mx1 = int(clamp(fx + (fw * 0.18), 0, proc_w - 1))
                        mx2 = int(clamp(fx + (fw * 0.82), mx1 + 1, proc_w))
                        my1 = int(clamp(fy + (fh * 0.56), 0, proc_h - 1))
                        my2 = int(clamp(fy + (fh * 0.96), my1 + 1, proc_h))
                        roi_now = gray[my1:my2, mx1:mx2]
                        roi_prev = prev_gray[my1:my2, mx1:mx2]
                        if roi_now.size > 0 and roi_now.shape == roi_prev.shape:
                            diff = cv2.absdiff(roi_now, roi_prev)
                            speaking_raw = float(diff.mean())
                            speaking_score = clamp(speaking_raw / 11.0, 0.0, 2.2)

                    total_score = (
                        speaking_score * 2.9
                        + sticky_score * 1.8
                        + area_score * 1.0
                        + stability_score * 0.7
                        + center_score * 0.5
                        + edge_score * 0.3
                    )

                    if total_score > best_score:
                        best_score = total_score
                        best = (fx, fy, fw, fh, cx_proc, cy_proc)

                if best is not None:
                    fx, fy, fw, fh, cx_proc, cy_proc = best
                    active_face = (
                        clamp(cx_proc * scale_x, 0.0, float(src_w)),
                        clamp(cy_proc * scale_y, 0.0, float(src_h)),
                    )
                    active_face_size = (
                        clamp(fw * scale_x, 10.0, float(src_w)),
                        clamp(fh * scale_y, 10.0, float(src_h)),
                    )
                    seeded = seed_track_points(cv2, gray, fx, fy, fw, fh)
                    if seeded is not None:
                        track_points = seeded
                    lost_face_frames = 0

        if active_face is not None:
            cx, cy = active_face
            desired_x = clamp(cx - (crop_w / 2.0), 0.0, float(src_w - crop_w))
            desired_y = clamp(cy - (crop_h * 0.37), 0.0, float(src_h - crop_h))
            if not detected_this_frame:
                lost_face_frames += 1
            else:
                lost_face_frames = 0
        else:
            lost_face_frames += 1

        if active_face is not None and lost_face_frames > int(max(20.0, fps * 1.6)):
            active_face = None
            active_face_size = None
            track_points = None

        if active_face is not None and (track_points is None or len(track_points) < 8):
            seed_w_proc = proc_w * 0.2
            seed_h_proc = proc_h * 0.2
            if active_face_size is not None:
                seed_w_proc = active_face_size[0] / float(scale_x)
                seed_h_proc = active_face_size[1] / float(scale_y)
            face_w = int(clamp(seed_w_proc, 16, proc_w))
            face_h = int(clamp(seed_h_proc, 16, proc_h))
            center_x = active_face[0] / float(scale_x)
            center_y = active_face[1] / float(scale_y)
            fx = int(clamp(center_x - (face_w / 2.0), 0, proc_w - 2))
            fy = int(clamp(center_y - (face_h / 2.0), 0, proc_h - 2))
            seeded = seed_track_points(cv2, gray, fx, fy, face_w, face_h)
            if seeded is not None:
                track_points = seeded

        if active_face is None and lost_face_frames > int(max(8.0, fps * 0.45)):
            desired_x = clamp((src_w - crop_w) / 2.0, 0.0, float(src_w - crop_w))
            desired_y = clamp((src_h - crop_h) / 2.0, 0.0, float(src_h - crop_h))

        prev_gray = gray
        # Deadzone to avoid micro jitter.
        if abs(desired_x - x) < 1.5:
            desired_x = x
        if abs(desired_y - y) < 1.5:
            desired_y = y

        # Adaptive smoothing: quick response for speaker switch, smooth for small movement.
        movement = math.hypot(desired_x - x, desired_y - y)
        if movement > (crop_w * 0.24):
            alpha = clamp(smooth - 0.28, 0.50, 0.90)
        elif movement > (crop_w * 0.10):
            alpha = clamp(smooth - 0.12, 0.56, 0.93)
        else:
            alpha = smooth

        next_x = (alpha * x) + ((1.0 - alpha) * desired_x)
        next_y = (alpha * y) + ((1.0 - alpha) * desired_y)

        max_step_x = max(3.0, crop_w * 0.11)
        max_step_y = max(3.0, crop_h * 0.11)
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
