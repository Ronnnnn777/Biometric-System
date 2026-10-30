from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import threading
from recognize_face import recognize, refresh_known_faces, known_faces

# The shared MediaPipe/dlib recognizer is not safe to run concurrently. Camera
# frames can arrive while the prior one is still being processed, so serialize
# recognition instead of returning a false "not recognized" service failure.
recognition_lock = threading.Lock()

class Handler(BaseHTTPRequestHandler):
    def send_json(self, status, payload):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        if self.path == "/refresh":
            try:
                length = int(self.headers.get('Content-Length', '0'))
                payload = json.loads(self.rfile.read(length)) if length else {}
                with recognition_lock:
                    refresh_known_faces()
                    encodings, _ = known_faces(payload.get('references', []))
                self.send_json(200,{"ok":True,"encoding_count":len(encodings),"reference_protocol":1})
            except Exception:
                self.send_json(500,{"ok":False})
            return
        if self.path != "/recognize": self.send_error(404); return
        try:
            length=int(self.headers.get("Content-Length","0")); payload=json.loads(self.rfile.read(length))
            if not recognition_lock.acquire(timeout=.15):
                self.send_json(200,{"match":False,"matches":[],"busy":True,"message":"Recognition is busy. Retrying…","reference_protocol":1})
                return
            try:
                result=recognize(payload.get("image_path", ""), payload.get('references'))
                result['reference_protocol'] = 1
            finally:
                recognition_lock.release()
            self.send_json(200,result)
        except Exception as error:
            self.send_json(500,{"match":False,"matches":[],"service_error":True,"message":"Recognition service error."})
    def log_message(self, format, *args): pass

# Keep the current scanner service isolated from older instances that can
# remain running after a code update.
# Use a dedicated port so an older service left running by another Windows
# session cannot keep the scanner on stale recognition rules.
# The face/landmark libraries run reliably on one server worker. The mobile
# scanner already waits for each frame response, so serial processing avoids
# concurrent native-model failures without reducing actual scan throughput.
ThreadingHTTPServer(("127.0.0.1",8774),Handler).serve_forever()
