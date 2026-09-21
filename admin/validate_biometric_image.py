import json
import os
import sys

import face_recognition
import numpy as np
from PIL import Image, ImageOps

try:
    import cv2
except ImportError:
    cv2 = None

MIN_IMAGE_EDGE = 480
MIN_FACE_EDGE = 100
MIN_SHARPNESS = 35.0

def fail(message):
    return {"valid": False, "message": message}

def validate(path):
    if not path or not os.path.isfile(path):
        return fail("The image could not be read. Please choose it again.")
    try:
        with Image.open(path) as source:
            if source.format not in ("JPEG", "PNG"):
                return fail("Only JPEG and PNG images are accepted for biometric registration.")
            orientation = source.getexif().get(274, 1)
            image = ImageOps.exif_transpose(source)
            # Correct photos taken on a phone that are marked with EXIF rotation
            # before face detection and before they are stored for the scanner.
            if orientation != 1:
                save_options = {"quality": 95} if source.format == "JPEG" else {}
                image.convert("RGB").save(path, format=source.format, **save_options)
            frame = np.asarray(image.convert("RGB"))
    except Exception:
        return fail("The image could not be read. Use a valid JPEG or PNG photo.")

    height, width = frame.shape[:2]
    if min(width, height) < MIN_IMAGE_EDGE:
        return fail("Use a clear photo that is at least 480 pixels wide and 480 pixels high.")
    locations = face_recognition.face_locations(frame, number_of_times_to_upsample=1, model="hog")
    if len(locations) == 0:
        return fail("No clear upright face was found. Use a front-facing photo in good lighting.")
    if len(locations) != 1:
        return fail("Use a photo with only one person visible.")

    top, right, bottom, left = locations[0]
    face_width, face_height = right - left, bottom - top
    if min(face_width, face_height) < MIN_FACE_EDGE:
        return fail("Move closer or use a higher-quality photo so the face is clear.")
    margin_x, margin_y = round(width * .04), round(height * .04)
    if left <= margin_x or right >= width - margin_x or top <= margin_y or bottom >= height - margin_y:
        return fail("Use an uncropped photo with the entire face visible and space around the head.")
    if cv2 is not None:
        face = frame[top:bottom, left:right]
        sharpness = float(cv2.Laplacian(cv2.cvtColor(face, cv2.COLOR_RGB2GRAY), cv2.CV_64F).var())
        if sharpness < MIN_SHARPNESS:
            return fail("The face photo is too blurry. Use a sharper, well-lit image.")
    return {"valid": True}

if __name__ == "__main__":
    print(json.dumps(validate(sys.argv[1] if len(sys.argv) > 1 else "")))
