from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import threading
from recognize_face_onnx import recognize, refresh_known_faces

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
                with recognition_lock:
                    length = int(self.headers.get("Content-Length", "0"))
                    payload = json.loads(self.rfile.read(length)) if length else {}
                    embeddings, _ = refresh_known_faces(payload.get("references"))
                self.send_json(200, {"ok": True, "engine": "insightface-onnx", "encoding_count": len(embeddings)})
            except Exception as error:
                self.send_json(500, {"ok": False, "message": str(error)})
            return
        if self.path != "/recognize":
            self.send_error(404)
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            payload = json.loads(self.rfile.read(length))
            if not recognition_lock.acquire(timeout=.15):
                self.send_json(200, {"match": False, "matches": [], "busy": True, "message": "Recognition is busy. Retrying…"})
                return
            try:
                result = recognize(payload.get("image_path", ""), payload.get("references"))
            finally:
                recognition_lock.release()
            self.send_json(200, result)
        except Exception:
            self.send_json(500, {"match": False, "matches": [], "service_error": True, "message": "ONNX recognition service error."})

    def log_message(self, format, *args):
        pass


# 8774 is the current reference-protocol recognizer. Keep ONNX separate.
ThreadingHTTPServer(("127.0.0.1", 8775), Handler).serve_forever()
