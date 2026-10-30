from http.server import BaseHTTPRequestHandler, HTTPServer
import json
import threading
from recognize_face import recognize, refresh_known_faces, known_faces

# The shared MediaPipe/dlib recognizer is not safe to run concurrently. Camera
# frames can arrive while the prior one is still being processed, so serialize
# recognition instead of returning a false "not recognized" service failure.
recognition_lock = threading.Lock()

class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path == "/refresh":
            try:
                length = int(self.headers.get('Content-Length', '0'))
                payload = json.loads(self.rfile.read(length)) if length else {}
                with recognition_lock:
                    refresh_known_faces()
                    encodings, _ = known_faces(payload.get('references', []))
                body=json.dumps({"ok":True,"encoding_count":len(encodings),"reference_protocol":1}).encode()
                self.send_response(200); self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(body))); self.end_headers(); self.wfile.write(body)
            except Exception:
                body=json.dumps({"ok":False}).encode()
                self.send_response(500); self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(body))); self.end_headers(); self.wfile.write(body)
            return
        if self.path != "/recognize": self.send_error(404); return
        try:
            length=int(self.headers.get("Content-Length","0")); payload=json.loads(self.rfile.read(length))
            with recognition_lock:
                result=recognize(payload.get("image_path", ""), payload.get('references'))
                result['reference_protocol'] = 1
            body=json.dumps(result).encode()
            self.send_response(200); self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(body))); self.end_headers(); self.wfile.write(body)
        except Exception as error:
            body=json.dumps({"match":False,"matches":[],"service_error":True,"message":"Recognition service error."}).encode(); self.send_response(500); self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(body))); self.end_headers(); self.wfile.write(body)
    def log_message(self, format, *args): pass

# Keep the current scanner service isolated from older instances that can
# remain running after a code update.
# Use a dedicated port so an older service left running by another Windows
# session cannot keep the scanner on stale recognition rules.
# The face/landmark libraries run reliably on one server worker. The mobile
# scanner already waits for each frame response, so serial processing avoids
# concurrent native-model failures without reducing actual scan throughput.
HTTPServer(("127.0.0.1",8774),Handler).serve_forever()
