import hashlib
import json
import os
import pickle
import sys
import threading
import time

import face_recognition
import numpy as np

try:
    import cv2
except ImportError:
    cv2 = None

try:
    import mediapipe as mp
    from mediapipe.tasks import python as mp_python
    from mediapipe.tasks.python import vision as mp_vision
except ImportError:
    mp = mp_python = mp_vision = None

KNOWN_FACES_DIR = r"C:\Biometric_Capstone\known_faces"
CACHE_PATH = os.path.join(os.path.dirname(__file__), "face_encoding_cache.pkl")
CACHE_VERSION = 4
_cached_key = None
_cached_encodings = []
_cached_ids = []
_next_fingerprint_check = 0.0

# This remains substantially stricter than face_recognition's default 0.60.
# The margin prevents a brighter/darker image from being attributed to a
# similarly-looking enrolled student.
# Webcam frames of the enrolled student can vary slightly from the enrollment
# portrait.  0.45 accepts those stable near-matches while remaining well below
# face_recognition's normal 0.60 cutoff; the runner-up margin below still
# rejects ambiguous identities.
MAX_MATCH_DISTANCE = 0.45
MIN_RUNNER_UP_MARGIN = 0.12
# An unmatched face near an enrolled profile can be caused by lighting, focus,
# or an older enrollment photo. It is ambiguous, not evidence of a visitor.
# Only a face this far from every enrolled profile may raise an unknown warning.
CLEAR_UNKNOWN_DISTANCE = 0.60
# 480px leaves a face large enough for reliable recognition at the scanner's
# normal distance while reducing HOG detector work on every daytime frame.
MAX_SCAN_WIDTH = 480
MIN_FACE_SIZE = 32
MIN_UNKNOWN_FACE_SHARPNESS = 25.0
FAR_FACE_CASCADE = cv2.CascadeClassifier(cv2.data.haarcascades + "haarcascade_frontalface_default.xml") if cv2 is not None else None
MEDIAPIPE_MODEL_PATH = os.path.join(os.path.dirname(__file__), "models", "face_landmarker.task")
_face_landmarker = None
_face_landmarker_checked = False
_face_landmarker_lock = threading.Lock()

def normalize_lighting(image):
    """Recover facial detail from uneven, dim, or backlit illumination."""
    if cv2 is None:
        return image
    lab = cv2.cvtColor(image, cv2.COLOR_RGB2LAB)
    l_channel, a_channel, b_channel = cv2.split(lab)
    clahe = cv2.createCLAHE(clipLimit=3.0, tileGridSize=(6, 6))
    balanced_luma = clahe.apply(l_channel)
    # Measure the shadow range, rather than the whole image: a bright window
    # can make a backlit frame's average brightness high while the face is
    # still nearly black.
    shadow_luma = float(np.percentile(l_channel, 35))
    if shadow_luma < 120:
        gamma = max(0.38, min(1.0, shadow_luma / 120.0))
        lookup = np.array([((value / 255.0) ** gamma) * 255 for value in range(256)], dtype=np.uint8)
        balanced_luma = cv2.LUT(balanced_luma, lookup)
    balanced = cv2.merge((balanced_luma, a_channel, b_channel))
    return cv2.cvtColor(balanced, cv2.COLOR_LAB2RGB)

def enhance_detail(image):
    """Apply a light unsharp mask to recover edges from a soft webcam frame."""
    if cv2 is None:
        return image
    soft = cv2.GaussianBlur(image, (0, 0), 1.15)
    return cv2.addWeighted(image, 1.45, soft, -0.45, 0)

def face_sharpness(image, location):
    """Estimate whether a face has enough edge detail for an unknown capture."""
    if cv2 is None:
        return float("inf")
    top, right, bottom, left = location
    face = image[max(0, top):max(0, bottom), max(0, left):max(0, right)]
    if face.size == 0:
        return 0.0
    gray = cv2.cvtColor(face, cv2.COLOR_RGB2GRAY)
    return float(cv2.Laplacian(gray, cv2.CV_64F).var())

def far_face_locations(image):
    """Fast fallback for smaller faces from students walking past the camera."""
    if FAR_FACE_CASCADE is None or FAR_FACE_CASCADE.empty():
        return []
    gray = cv2.cvtColor(image, cv2.COLOR_RGB2GRAY)
    boxes = FAR_FACE_CASCADE.detectMultiScale(gray, scaleFactor=1.15, minNeighbors=4, minSize=(30, 30))
    return [(int(y), int(x + width), int(y + height), int(x)) for x, y, width, height in boxes]

def get_face_landmarker():
    """Load MediaPipe once for the scanner service; fall back safely if absent."""
    global _face_landmarker, _face_landmarker_checked
    if _face_landmarker_checked:
        return _face_landmarker
    with _face_landmarker_lock:
        if _face_landmarker_checked:
            return _face_landmarker
        _face_landmarker_checked = True
        if mp is None or not os.path.isfile(MEDIAPIPE_MODEL_PATH):
            return None
        try:
            options = mp_vision.FaceLandmarkerOptions(
                base_options=mp_python.BaseOptions(model_asset_path=MEDIAPIPE_MODEL_PATH),
                running_mode=mp_vision.RunningMode.IMAGE,
                num_faces=8,
                min_face_detection_confidence=0.60,
                min_face_presence_confidence=0.60,
            )
            _face_landmarker = mp_vision.FaceLandmarker.create_from_options(options)
        except Exception:
            _face_landmarker = None
    return _face_landmarker

def mediapipe_face_details(image):
    """Return landmark-backed face boxes and whether each face is front-facing."""
    landmarker = get_face_landmarker()
    if landmarker is None:
        return []
    height, width = image.shape[:2]
    try:
        mp_image = mp.Image(image_format=mp.ImageFormat.SRGB, data=np.ascontiguousarray(image))
        with _face_landmarker_lock:
            result = landmarker.detect(mp_image)
    except Exception:
        return []
    details = []
    for landmarks in result.face_landmarks:
        if not landmarks:
            continue
        top = max(0, int(min(point.y for point in landmarks) * height))
        right = min(width, int(max(point.x for point in landmarks) * width))
        bottom = min(height, int(max(point.y for point in landmarks) * height))
        left = max(0, int(min(point.x for point in landmarks) * width))
        if right - left < MIN_FACE_SIZE or bottom - top < MIN_FACE_SIZE:
            continue
        # Landmark 33 and 263 are eye corners; 1 is the nose tip. A centered
        # nose and level eyes provide a lightweight frontal-pose quality gate.
        left_eye, right_eye, nose = landmarks[33], landmarks[263], landmarks[1]
        eye_span = abs(right_eye.x - left_eye.x)
        nose_position = (nose.x - min(left_eye.x, right_eye.x)) / eye_span if eye_span else -1
        frontal = 0.30 <= nose_position <= 0.70 and abs(left_eye.y - right_eye.y) <= eye_span * 0.25
        details.append({"location": (top, right, bottom, left), "frontal": frontal})
    return details

def location_overlap(first, second):
    top = max(first[0], second[0])
    right = min(first[1], second[1])
    bottom = min(first[2], second[2])
    left = max(first[3], second[3])
    intersection = max(0, right - left) * max(0, bottom - top)
    first_area = max(1, (first[1] - first[3]) * (first[2] - first[0]))
    second_area = max(1, (second[1] - second[3]) * (second[2] - second[0]))
    return intersection / min(first_area, second_area)

def resize_for_scan(frame, max_width=MAX_SCAN_WIDTH):
    """Reduce detector work while retaining enough pixels for a face match."""
    if cv2 is None or frame.shape[1] <= max_width:
        return frame, 1.0
    ratio = max_width / frame.shape[1]
    resized = cv2.resize(frame, (MAX_SCAN_WIDTH, round(frame.shape[0] * ratio)), interpolation=cv2.INTER_AREA)
    return resized, 1.0 / ratio

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
    global _cached_key, _cached_encodings, _cached_ids, _next_fingerprint_check
    # Directory metadata does not need to be checked for every camera frame.
    # New/changed enrollments are picked up within a few seconds instead.
    if _cached_encodings and time.monotonic() < _next_fingerprint_check:
        return _cached_encodings, _cached_ids
    key = fingerprint()
    _next_fingerprint_check = time.monotonic() + 8.0
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
        # Alternate eMeet-angle photos can be tighter or slightly blurred.
        # Spend the extra detection pass only while enrolling a new reference.
        if not locations:
            locations = face_recognition.face_locations(image, number_of_times_to_upsample=1, model="hog")
        # Tight camera-angle crops can be hard for HOG to locate even though
        # they are valid reference images. Use the same lightweight fallback
        # used by the live scanner so the reference is not silently skipped.
        if not locations:
            locations = far_face_locations(image)
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

def refresh_known_faces():
    """Make a newly enrolled face available to the live scanner immediately."""
    global _next_fingerprint_check
    _next_fingerprint_check = 0.0
    return known_faces()

def best_match(known, ids, candidates, max_distance=MAX_MATCH_DISTANCE, min_margin=MIN_RUNNER_UP_MARGIN):
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
    if best_distance <= max_distance and runner_up - best_distance >= min_margin:
        return best_id, best_distance
    return None

def batch_best_matches(known, ids, candidates, max_distance=MAX_MATCH_DISTANCE, min_margin=MIN_RUNNER_UP_MARGIN):
    """Match every face in a frame with one vectorized distance calculation."""
    if not known or not candidates:
        return [None] * len(candidates)
    distances = np.linalg.norm(
        np.asarray(candidates)[:, None, :] - np.asarray(known)[None, :, :], axis=2
    )
    matches = []
    for candidate_distances in distances:
        per_student = {}
        for index, distance in enumerate(candidate_distances):
            student_id = ids[index]
            per_student[student_id] = min(per_student.get(student_id, float("inf")), float(distance))
        ranked = sorted(per_student.items(), key=lambda item: item[1])
        if not ranked:
            matches.append(None)
            continue
        best_id, best_distance = ranked[0]
        runner_up = ranked[1][1] if len(ranked) > 1 else float("inf")
        matches.append((best_id, best_distance) if best_distance <= max_distance and runner_up - best_distance >= min_margin else None)
    return matches

def nearest_known_distance(known, candidate):
    if not known or candidate is None:
        return float("inf")
    distances = face_recognition.face_distance(known, candidate)
    return float(np.min(distances)) if len(distances) else float("inf")

def is_frontal_unknown_face(image, location):
    """Only create visitor alerts for a face turned sufficiently toward the camera."""
    try:
        landmarks = face_recognition.face_landmarks(image, [location])
    except Exception:
        return False
    if not landmarks:
        return False
    points = landmarks[0]
    left_eye, right_eye, nose = points.get("left_eye"), points.get("right_eye"), points.get("nose_tip")
    if not left_eye or not right_eye or not nose:
        return False
    left_x = float(np.mean([point[0] for point in left_eye]))
    right_x = float(np.mean([point[0] for point in right_eye]))
    nose_x = float(np.mean([point[0] for point in nose]))
    eye_span = abs(right_x - left_x)
    if eye_span < 12:
        return False
    nose_position = (nose_x - min(left_x, right_x)) / eye_span
    # A profile hides or heavily compresses one eye, moving the nose far from
    # the center between the eyes. Keep that out of visitor notifications.
    return 0.30 <= nose_position <= 0.70

def recognize(image_path):
    if not image_path or not os.path.isfile(image_path):
        return {"match": False, "faces": [], "matches": [], "message": "No camera frame received."}
    frame = face_recognition.load_image_file(image_path)
    scan_frame, box_scale = resize_for_scan(frame)
    normalized_frame = None
    detail_frame = None
    far_mode = False
    # MediaPipe Face Landmarker provides stable landmark-backed face boxes and
    # pose checks. HOG/Haar remain fallbacks for distant or difficult frames.
    landmark_details = mediapipe_face_details(scan_frame)
    locations = [detail["location"] for detail in landmark_details]
    landmark_frontal = {detail["location"]: detail["frontal"] for detail in landmark_details}
    if not locations:
        locations = face_recognition.face_locations(scan_frame, number_of_times_to_upsample=0, model="hog")
    far_location_keys = set()
    # HOG is quick for close faces, but can miss a smaller child beside an
    # adult. Add lightweight Haar detections instead of treating the first
    # HOG face as the only person in the frame.
    if len(locations) < 2:
        for location in far_face_locations(scan_frame):
            if not any(location_overlap(location, existing) >= 0.55 for existing in locations):
                locations.append(location)
                far_location_keys.add(location)
    if not locations:
        # Haar is much faster than a second HOG pass and retains the original
        # camera resolution for a student walking farther from the lens.
        locations = far_face_locations(frame)
        if locations:
            scan_frame, box_scale, far_mode = frame, 1.0, True
    if not locations:
        normalized_frame = normalize_lighting(scan_frame)
        if normalized_frame is not scan_frame:
            # Use the same HOG detector with one controlled upsample. The
            # previous Haar fallback could draw boxes around bright textures.
            locations = face_recognition.face_locations(normalized_frame, number_of_times_to_upsample=1, model="hog")
    if not locations:
        return {"match": False, "faces": [], "matches": [], "message": "No face detected."}

    known, ids = known_faces()
    eligible_faces = []
    for location in locations:
        top, right, bottom, left = location
        if right - left < MIN_FACE_SIZE or bottom - top < MIN_FACE_SIZE:
            continue
        # The HOG detector can find a small far-away face on the fast pass.
        # Give only that face the guarded far-match treatment.
        is_far = far_mode or location in far_location_keys or right - left < 50 or bottom - top < 50
        box = {"top": round(top * box_scale), "right": round(right * box_scale), "bottom": round(bottom * box_scale), "left": round(left * box_scale)}
        eligible_faces.append((location, box, face_sharpness(scan_frame, location), is_far, landmark_frontal.get(location)))

    # Encode every detected face in one call. This reduces native-call
    # overhead and processes a group walking past the camera as one frame.
    # Do not store an unreliable low-detail frame as an unregistered person.
    # It can still be recognized if the embedding is sufficiently confident.
    detected_faces = [{"box": box} for _, box, _, _, _ in eligible_faces]
    faces = [{"box": box} for _, box, sharpness, _, _ in eligible_faces if sharpness >= MIN_UNKNOWN_FACE_SHARPNESS]
    fast_encodings = face_recognition.face_encodings(scan_frame, [location for location, _, _, _, _ in eligible_faces]) if eligible_faces else []
    normalized_encodings = None
    detail_encodings = None
    normalized_matches = None
    detail_matches = None
    fast_matches = [None] * len(eligible_faces)
    normal_indexes = [index for index, (_, _, _, is_far, _) in enumerate(eligible_faces) if not is_far and index < len(fast_encodings)]
    far_indexes = [index for index, (_, _, _, is_far, _) in enumerate(eligible_faces) if is_far and index < len(fast_encodings)]
    # A distant face is less reliable, never more reliable. Keep the same
    # identity threshold and runner-up margin to avoid an incorrect student.
    for indexes, max_distance, min_margin in ((normal_indexes, MAX_MATCH_DISTANCE, MIN_RUNNER_UP_MARGIN), (far_indexes, MAX_MATCH_DISTANCE, MIN_RUNNER_UP_MARGIN)):
        for index, match in zip(indexes, batch_best_matches(known, ids, [fast_encodings[index] for index in indexes], max_distance, min_margin)):
            fast_matches[index] = match
    matches, seen = [], set()
    face_matches = [None] * len(eligible_faces)
    for index, (_, box, _, is_far, _) in enumerate(eligible_faces):
        match = fast_matches[index]
        if not match:
            if normalized_frame is None:
                normalized_frame = normalize_lighting(scan_frame)
            if normalized_frame is not scan_frame and normalized_encodings is None:
                normalized_encodings = face_recognition.face_encodings(normalized_frame, [location for location, _, _, _, _ in eligible_faces])
            if normalized_encodings is not None and normalized_matches is None:
                # Lighting correction must never make identity matching more
                # permissive.  A backlit or low-detail face is safer to treat
                # as unregistered than to attribute it to another student.
                normalized_matches = batch_best_matches(known, ids, normalized_encodings, max_distance=0.45, min_margin=0.14)
            if normalized_matches is not None:
                match = normalized_matches[index] if index < len(normalized_matches) else None
        if not match:
            if detail_frame is None:
                detail_frame = enhance_detail(scan_frame)
            if detail_frame is not scan_frame and detail_encodings is None:
                detail_encodings = face_recognition.face_encodings(detail_frame, [location for location, _, _, _, _ in eligible_faces])
            if detail_encodings is not None and detail_matches is None:
                detail_matches = batch_best_matches(known, ids, detail_encodings, max_distance=0.45, min_margin=0.14)
            if detail_matches is not None:
                match = detail_matches[index] if index < len(detail_matches) else None
        face_matches[index] = match
        if match and match[0] not in seen:
            seen.add(match[0])
            matches.append({"student_id": match[0], "confidence": round(float(1 - match[1]), 3), "distance": round(float(match[1]), 3), "box": box, "far": is_far})
    # A side/partial face may be too ambiguous to identify, but it is not
    # evidence of a visitor. Require frontal landmarks before alerting admin.
    unregistered_faces = []
    for index, (location, box, sharpness, _, landmark_is_frontal) in enumerate(eligible_faces):
        if face_matches[index] is not None or sharpness < MIN_UNKNOWN_FACE_SHARPNESS:
            continue
        frontal = landmark_is_frontal if landmark_is_frontal is not None else is_frontal_unknown_face(scan_frame, location)
        if not frontal:
            continue
        candidate = fast_encodings[index] if index < len(fast_encodings) else None
        if nearest_known_distance(known, candidate) >= CLEAR_UNKNOWN_DISTANCE:
            unregistered_faces.append({"box": box})
    message = None if matches else ("Camera image is too blurry. Hold still while the eMeet refocuses." if eligible_faces and not faces else "Face not recognized.")
    return {"match": bool(matches), "faces": faces, "detected_faces": detected_faces, "unregistered_faces": unregistered_faces, "matches": matches, "message": message}

if __name__ == "__main__":
    print(json.dumps(recognize(sys.argv[1] if len(sys.argv) > 1 else "")))
