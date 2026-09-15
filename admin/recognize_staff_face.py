import hashlib
import json
import os
import sys
import time

import face_recognition
import numpy as np

# Match the attendance scanner's inexpensive 480px pass and cache-first
# design, while keeping staff matching stricter than student attendance.
MAX_SCAN_WIDTH = 480
MAX_DISTANCE = 0.42
MIN_MARGIN = 0.12
_cache_key = None
_cache_encodings = []
_cache_ids = []
_next_refresh = 0.0

def resize_for_scan(image):
    if image.shape[1] <= MAX_SCAN_WIDTH:
        return image, 1.0
    ratio = MAX_SCAN_WIDTH / image.shape[1]
    import cv2
    return cv2.resize(image, (MAX_SCAN_WIDTH, round(image.shape[0] * ratio)), interpolation=cv2.INTER_AREA), 1.0 / ratio

def fingerprint(directory):
    entries = []
    if os.path.isdir(directory):
        for name in sorted(os.listdir(directory)):
            path = os.path.join(directory, name)
            if name.lower().endswith((".jpg", ".jpeg", ".png")) and os.path.isfile(path):
                entries.append(f"{name}:{os.path.getmtime(path)}:{os.path.getsize(path)}")
    return hashlib.sha256("|".join(entries).encode()).hexdigest()

def known_faces(directory):
    global _cache_key, _cache_encodings, _cache_ids, _next_refresh
    if _cache_encodings and time.monotonic() < _next_refresh:
        return _cache_encodings, _cache_ids
    key = fingerprint(directory)
    _next_refresh = time.monotonic() + 8
    if key == _cache_key:
        return _cache_encodings, _cache_ids
    encodings, identities = [], []
    if os.path.isdir(directory):
        for filename in sorted(os.listdir(directory)):
            if not filename.lower().endswith((".jpg", ".jpeg", ".png")):
                continue
            try:
                image = face_recognition.load_image_file(os.path.join(directory, filename))
                locations = face_recognition.face_locations(image, number_of_times_to_upsample=0, model="hog")
                if len(locations) != 1:
                    continue
                encoded = face_recognition.face_encodings(image, locations)
                if encoded:
                    encodings.append(encoded[0]); identities.append(os.path.splitext(filename)[0])
            except Exception:
                continue
    _cache_key, _cache_encodings, _cache_ids = key, encodings, identities
    return encodings, identities

def recognize(path, directory):
    if not path or not os.path.isfile(path):
        return {"match": False, "face_count": 0, "matches": [], "faces": [], "message": "No camera frame received."}
    try:
        frame = face_recognition.load_image_file(path)
        scan, scale = resize_for_scan(frame)
        locations = face_recognition.face_locations(scan, number_of_times_to_upsample=0, model="hog")
        if not locations:
            locations = face_recognition.face_locations(scan, number_of_times_to_upsample=1, model="hog")
        boxes = [{"top": round(top * scale), "right": round(right * scale), "bottom": round(bottom * scale), "left": round(left * scale)} for top, right, bottom, left in locations]
        if not locations:
            return {"match": False, "face_count": 0, "matches": [], "faces": [], "frame_width": frame.shape[1], "frame_height": frame.shape[0], "message": "No face detected."}
        known, identities = known_faces(directory)
        encodings = face_recognition.face_encodings(scan, locations)
        matches = []
        if known:
            for index, candidate in enumerate(encodings):
                distances = np.asarray(face_recognition.face_distance(known, candidate), dtype=float)
                order = np.argsort(distances)
                best = int(order[0]); distance = float(distances[best])
                runner_up = float(distances[order[1]]) if len(order) > 1 else float("inf")
                if distance <= MAX_DISTANCE and runner_up - distance >= MIN_MARGIN:
                    matches.append({"identity": identities[best], "distance": round(distance, 3), "confidence": round(1 - distance, 3), "box": boxes[index]})
        return {"match": bool(matches), "face_count": len(locations), "matches": matches, "faces": [{"box": box} for box in boxes], "frame_width": frame.shape[1], "frame_height": frame.shape[0], "message": None if matches else "Face not recognized."}
    except Exception:
        return {"match": False, "face_count": 0, "matches": [], "faces": [], "message": "Face recognition service is unavailable."}

if __name__ == "__main__":
    print(json.dumps(recognize(sys.argv[1] if len(sys.argv) > 1 else "", sys.argv[2] if len(sys.argv) > 2 else "")))
