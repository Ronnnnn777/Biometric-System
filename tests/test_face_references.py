"""Run with the scanner environment: python -m unittest discover -s tests -p test_face_references.py"""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import numpy as np

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('student_recognizer', ROOT / 'admin/recognize_face.py')
engine = importlib.util.module_from_spec(spec)
spec.loader.exec_module(engine)
quality_spec = importlib.util.spec_from_file_location('student_quality', ROOT / 'admin/validate_biometric_image.py')
quality = importlib.util.module_from_spec(quality_spec)
quality_spec.loader.exec_module(quality)


class ReferenceMatchingTests(unittest.TestCase):
    def vector(self, value):
        result = np.zeros(128)
        result[0] = value
        return result

    def test_one_two_three_references_are_one_identity(self):
        for values in ([0.2], [0.2, 0.25], [0.2, 0.25, 0.3]):
            matches = engine.batch_best_matches([self.vector(v) for v in values], ['a'] * len(values), [self.vector(0)])
            self.assertEqual(matches[0][0], 'a')

    def test_side_angle_reference_recovers_candidate_without_relaxing_threshold(self):
        known = [self.vector(0), self.vector(0.9), self.vector(-0.9)]
        self.assertIsNone(engine.batch_best_matches(known[:1], ['a'], [self.vector(0.9)])[0])
        self.assertEqual(engine.batch_best_matches(known, ['a'] * 3, [self.vector(0.9), self.vector(-0.9)])[0][0], 'a')
        self.assertEqual(engine.batch_best_matches(known, ['a'] * 3, [self.vector(-0.9)])[0][0], 'a')

    def test_unknown_and_ambiguous_are_rejected(self):
        self.assertIsNone(engine.batch_best_matches([self.vector(0)], ['a'], [self.vector(1)])[0])
        self.assertIsNone(engine.batch_best_matches([self.vector(.4), self.vector(.42)], ['a', 'b'], [self.vector(0)])[0])

    def test_runner_up_is_another_student(self):
        self.assertEqual(engine.batch_best_matches([self.vector(.3), self.vector(.31), self.vector(.5)], ['a','a','b'], [self.vector(0)])[0][0], 'a')

    def test_multiple_faces_match_independently(self):
        result=engine.batch_best_matches([self.vector(0), self.vector(2)], ['a','b'], [self.vector(.1),self.vector(2.1),self.vector(4)])
        self.assertEqual([item[0] if item else None for item in result], ['a','b',None])

    def test_frame_deduplicates_several_matches_for_same_student(self):
        from PIL import Image
        with tempfile.TemporaryDirectory() as directory:
            frame=Path(directory)/'frame.png'
            Image.fromarray(np.full((480,480,3),128,dtype=np.uint8)).save(frame)
            locations=[(50,200,200,50),(240,400,400,240)]
            with patch.object(engine,'mediapipe_face_details',return_value=[]), patch.object(engine,'far_face_locations',return_value=[]), patch.object(engine.face_recognition,'face_locations',return_value=locations), patch.object(engine.face_recognition,'face_encodings',return_value=[self.vector(0),self.vector(.1)]), patch.object(engine,'known_faces',return_value=([self.vector(0)],['a'])):
                result=engine.recognize(str(frame), [])
            self.assertEqual([item['student_id'] for item in result['matches']],['a'])

    def test_cache_reuses_replaces_removes_and_restarts(self):
        with tempfile.TemporaryDirectory() as directory:
            reference=Path(directory)/'front.jpg'
            reference.write_bytes(b'fake-image')
            refs=[{'student_id':'a','pose':'front','path':str(reference)}]
            with patch.object(engine,'CACHE_PATH',os.path.join(directory,'cache.pkl')), patch.object(engine,'encode_reference',return_value=[self.vector(0)]) as encode:
                engine._cached_key=None; engine._reference_cache={}
                self.assertEqual(engine.known_faces(refs)[1], ['a'])
                engine.known_faces(refs)
                self.assertEqual(encode.call_count,1)
                reference.write_bytes(b'replacement-image')
                engine.known_faces(refs)
                self.assertEqual(encode.call_count,2)
                engine._cached_key=None; engine._reference_cache={}
                engine.known_faces(refs)
                self.assertEqual(encode.call_count,2)
                with patch.object(engine,'MODEL_SIGNATURE',['changed-model']):
                    engine.known_faces(refs)
                    self.assertEqual(encode.call_count,3)
                self.assertEqual(engine.known_faces([]),([],[]))
                self.assertEqual(engine._reference_cache,{})


class ReferenceQualityTests(unittest.TestCase):
    def validate_frame(self, frame, pose='front', locations=None, sharpness=100):
        from PIL import Image
        if quality.cv2 is None:
            self.skipTest('OpenCV required for image quality validation')
        landmarks={'left_eye':[(220,240)]*6, 'right_eye':[(420,240)]*6,
                   'nose_tip':[(320,340)]*5, 'top_lip':[(260,420),(380,420)]}
        with tempfile.TemporaryDirectory() as directory:
            photo=Path(directory)/'reference.png'
            Image.fromarray(frame).save(photo)
            with patch.object(quality.face_recognition,'face_locations',return_value=locations if locations is not None else [(100,500,500,100)]), patch.object(quality.face_recognition,'face_landmarks',return_value=[landmarks]), patch.object(quality.face_recognition,'face_encodings',return_value=[np.zeros(128)]), patch.object(quality.cv2,'Laplacian',return_value=np.array([0,2*np.sqrt(sharpness)])):
                return quality.validate(str(photo),pose)

    def texture(self, low=50, high=205):
        return np.random.default_rng(1).integers(low,high,(640,640,3),dtype=np.uint8)

    def test_clear_front_and_wrong_pose(self):
        self.assertTrue(self.validate_frame(self.texture())['valid'])
        self.assertFalse(self.validate_frame(self.texture(),'left')['valid'])

    def test_blurry_reference_is_rejected(self):
        result=self.validate_frame(self.texture(),sharpness=1)
        self.assertIn('blurry',result['message'])

    def test_multiple_faces_are_rejected(self):
        result=self.validate_frame(self.texture(),locations=[(100,500,500,100),(50,200,200,50)])
        self.assertIn('one person',result['message'])

    def test_dark_overexposed_and_low_contrast_are_rejected(self):
        self.assertIn('dark',self.validate_frame(self.texture(0,35))['message'])
        self.assertIn('overexposed',self.validate_frame(self.texture(225,255))['message'])
        self.assertIn('detail',self.validate_frame(np.full((640,640,3),128,dtype=np.uint8))['message'])


if __name__=='__main__': unittest.main()
