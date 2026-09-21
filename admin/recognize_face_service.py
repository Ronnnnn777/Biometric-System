from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from recognize_face import recognize, refresh_known_faces

class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path == "/refresh":
            try:
                encodings, _ = refresh_known_faces()
                body=json.dumps({"ok":True,"encoding_count":len(encodings)}).encode()
                self.send_response(200); self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(body))); self.end_headers(); self.wfile.write(body)
            except Exception:
                body=json.dumps({"ok":False}).encode()
                self.send_response(500); self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(body))); self.end_headers(); self.wfile.write(body)
            return
        if self.path != "/recognize": self.send_error(404); return
        try:
            length=int(self.headers.get("Content-Length","0")); payload=json.loads(self.rfile.read(length)); result=recognize(payload.get("image_path", "")); body=json.dumps(result).encode()
            self.send_response(200); self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(body))); self.end_headers(); self.wfile.write(body)
        except Exception as error:
            body=json.dumps({"match":False,"matches":[],"message":"Recognition service error."}).encode(); self.send_response(500); self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(body))); self.end_headers(); self.wfile.write(body)
    def log_message(self, format, *args): pass

# Keep the current scanner service isolated from older instances that can
# remain running after a code update.
# Use a dedicated port so an older service left running by another Windows
# session cannot keep the scanner on stale recognition rules.
ThreadingHTTPServer(("127.0.0.1",8772),Handler).serve_forever()
