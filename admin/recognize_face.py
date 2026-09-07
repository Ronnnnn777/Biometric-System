import hashlib
import json
import os
import pickle
import sys
import face_recognition

KNOWN_FACES_DIR = r"C:\Biometric_Capstone\known_faces"
CACHE_PATH = os.path.join(os.path.dirname(__file__), "face_encoding_cache.pkl")
_cached_key = None
_cached_encodings = []
_cached_ids = []

def fingerprint():
    entries=[]
    for name in sorted(os.listdir(KNOWN_FACES_DIR)):
        if name.lower().endswith((".jpg", ".jpeg", ".png")):
            path=os.path.join(KNOWN_FACES_DIR,name)
            entries.append(f"{name}:{os.path.getmtime(path)}:{os.path.getsize(path)}")
    return hashlib.sha256("|".join(entries).encode()).hexdigest()

def known_faces():
    global _cached_key,_cached_encodings,_cached_ids
    key=fingerprint()
    if key==_cached_key: return _cached_encodings,_cached_ids
    try:
        with open(CACHE_PATH,"rb") as f: cache=pickle.load(f)
        if cache["fingerprint"]==key:
            _cached_key,_cached_encodings,_cached_ids=key,cache["encodings"],cache["ids"]
            return _cached_encodings,_cached_ids
    except Exception: pass
    encodings=[];ids=[]
    for filename in os.listdir(KNOWN_FACES_DIR):
        if not filename.lower().endswith((".jpg", ".jpeg", ".png")): continue
        faces=face_recognition.face_encodings(face_recognition.load_image_file(os.path.join(KNOWN_FACES_DIR,filename)))
        if faces: encodings.append(faces[0]);ids.append(os.path.splitext(filename)[0].split("_")[0])
    with open(CACHE_PATH,"wb") as f: pickle.dump({"fingerprint":key,"encodings":encodings,"ids":ids},f)
    _cached_key,_cached_encodings,_cached_ids=key,encodings,ids
    return encodings,ids

def recognize(image_path):
    if not image_path or not os.path.isfile(image_path): return {"match":False,"faces":[],"matches":[],"message":"No camera frame received."}
    frame=face_recognition.load_image_file(image_path)
    locations=face_recognition.face_locations(frame,number_of_times_to_upsample=0,model="hog")
    encodings=face_recognition.face_encodings(frame,locations)
    if not encodings: return {"match":False,"faces":[],"matches":[],"message":"No face detected."}
    known,ids=known_faces();matches=[];seen=set();faces=[]
    for encoding,location in zip(encodings,locations):
        top,right,bottom,left=location
        if right-left < 55 or bottom-top < 55: continue
        box={"top":int(top),"right":int(right),"bottom":int(bottom),"left":int(left)};faces.append({"box":box})
        distances=face_recognition.face_distance(known,encoding)
        if len(distances):
            best=distances.argmin()
            if distances[best]<=0.38 and ids[best] not in seen:
                seen.add(ids[best]);matches.append({"student_id":ids[best],"confidence":round(float(1-distances[best]),3),"box":box})
    return {"match":bool(matches),"faces":faces,"matches":matches,"message":"Face not recognized." if not matches else None}

if __name__ == "__main__": print(json.dumps(recognize(sys.argv[1] if len(sys.argv)>1 else "")))