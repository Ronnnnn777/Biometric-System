import hashlib
import json
import os
import pickle
import sys

import face_recognition

try:
    import cv2
except ImportError:
    cv2 = None

KNOWN_FACES_DIR = r"C:\Biometric_Capstone\known_faces"
CACHE_PATH = os.path.join(os.path.dirname(__file__), "face_encoding_cache.pkl")
CACHE_VERSION = 2
_cached_key = None
_cached_encodings = []
_cached_ids = []

# This remains substantially stricter than face_recognition's default 0.60.
# The margin prevents a brighter/darker image from being attributed to a
# similarly-looking enrolled student.
MAX_MATCH_DISTANCE = 0.47
MIN_RUNNER_UP_MARGIN = 0.06

def normalize_lighting(image):
    """Equalize illumination only; face embeddings still exclude the background."""
    if cv2 is None:
        return image
    lab = cv2.cvtColor(image, cv2.COLOR_RGB2LAB)
    l_channel, a_channel, b_channel = cv2.split(lab)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    balanced = cv2.merge((clahe.apply(l_channel), a_channel, b_channel))
    return cv2.cvtColor(balanced, cv2.COLOR_LAB2RGB)

def fingerprint():
    entries = []
    for name in sorted(os.listdir(KNOWN_FACES_DIR)):
        if name.lower().endswith((".jpg", ".jpeg", ".png")):
            path = os.path.join(KNOWN_FACES_DIR, name)
            entries.append(f"{name}:{os.path.getmtime(path)}:{os.path.getsize(path)}")
    return hashlib.sha256((str(CACHE_VERSION) + "|" + "|".join(entries)).encode()).hexdigest()

def face_variants(image, locations):
    variants = []
    original = face_recognition.face_encodings(image, locations)
    variants.extend(original)
    normalized = normalize_lighting(image)
    if normalized is not image:
        variants.extend(face_recognition.face_encodings(normalized, locations))
    return variants

def known_faces():
    global _cached_key, _cached_encodings, _cached_ids
    key = fingerprint()
    if key == _cached_key:
        return _cached_encodings, _cached_ids
    try:
        with open(CACHE_PATH, "rb") as file:
            cache = pickle.load(file)
        if cache["fingerprint"] == key:
            _cached_key, _cached_encodings, _cached_ids = key, cache["encodings"], cache["ids"]
            return _cached_encodings, _cached_ids
    except Exception:
        pass

    encodings, ids = [], []
    for filename in os.listdir(KNOWN_FACES_DIR):
        if not filename.lower().endswith((".jpg", ".jpeg", ".png")):
            continue
        image = face_recognition.load_image_file(os.path.join(KNOWN_FACES_DIR, filename))
        locations = face_recognition.face_locations(image, number_of_times_to_upsample=0, model="hog")
        if not locations:
            continue
        student_id = os.path.splitext(filename)[0].split("_")[0]
        for encoding in face_variants(image, [locations[0]]):
            encodings.append(encoding)
            ids.append(student_id)
    with open(CACHE_PATH, "wb") as file:
        pickle.dump({"fingerprint": key, "encodings": encodings, "ids": ids}, file)
    _cached_key, _cached_encodings, _cached_ids = key, encodings, ids
    return encodings, ids

def best_match(known, ids, candidates):
    per_student = {}
    for candidate in candidates:
        for index, distance in enumerate(face_recognition.face_distance(known, candidate)):
            student_id = ids[index]
            per_student[student_id] = min(per_student.get(student_id, float("inf")), float(distance))
    if not per_student:
        return None
    ranked = sorted(per_student.items(), key=lambda item: item[1])
    best_id, best_distance = ranked[0]
    runner_up = ranked[1][1] if len(ranked) > 1 else float("inf")
    if best_distance <= MAX_MATCH_DISTANCE and runner_up - best_distance >= MIN_RUNNER_UP_MARGIN:
        return best_id, best_distance
    return None

def recognize(image_path):
    if not image_path or not os.path.isfile(image_path):
        return {"match": False, "faces": [], "matches": [], "message": "No camera frame received."}
    frame = face_recognition.load_image_file(image_path)
    normalized = normalize_lighting(frame)
    locations = face_recognition.face_locations(frame, number_of_times_to_upsample=0, model="hog")
    if not locations and normalized is not frame:
        locations = face_recognition.face_locations(normalized, number_of_times_to_upsample=0, model="hog")
    if not locations:
        return {"match": False, "faces": [], "matches": [], "message": "No face detected."}

    known, ids = known_faces()
    matches, faces, seen = [], [], set()
    for location in locations:
        top, right, bottom, left = location
        if right - left < 45 or bottom - top < 45:
            continue
        box = {"top": int(top), "right": int(right), "bottom": int(bottom), "left": int(left)}
        faces.append({"box": box})
        candidates = face_variants(frame, [location])
        match = best_match(known, ids, candidates) if known and candidates else None
        if match and match[0] not in seen:
            seen.add(match[0])
            matches.append({"student_id": match[0], "confidence": round(float(1 - match[1]), 3), "box": box})
    return {"match": bool(matches), "faces": faces, "matches": matches, "message": "Face not recognized." if not matches else None}

if __name__ == "__main__":
    print(json.dumps(recognize(sys.argv[1] if len(sys.argv) > 1 else "")))
