from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from recognize_staff_face import recognize

class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path != "/recognize":
            self.send_error(404)
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            payload = json.loads(self.rfile.read(length))
            body = json.dumps(recognize(payload.get("image_path", ""), payload.get("directory", ""))).encode()
            self.send_response(200)
        except Exception:
            body = json.dumps({"match": False, "matches": [], "faces": [], "message": "Face recognition service is unavailable."}).encode()
            self.send_response(500)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)
    def log_message(self, format, *args): pass

ThreadingHTTPServer(("127.0.0.1", 8773), Handler).serve_forever()
