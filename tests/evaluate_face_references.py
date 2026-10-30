"""Evaluate authorized held-out frames. Never emits images or embedding vectors."""
import argparse
from collections import defaultdict
import json
from pathlib import Path
import statistics
import sys
import tempfile
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'admin'))
import recognize_face as engine


def summarize(records):
    known = [row for row in records if row['expected']]
    unknown = [row for row in records if not row['expected']]
    expected_count = sum(len(row['expected']) for row in known)
    misses = sum(len(set(row['expected']) - set(row['predicted'])) for row in known)
    return {
        'frames': len(records),
        'FAR_unknown_frames': sum(bool(row['predicted']) for row in unknown) / len(unknown) if unknown else None,
        'FRR_enrolled_faces': misses / expected_count if expected_count else None,
        'exact_identity_success_rate': sum(set(row['expected']) == set(row['predicted']) for row in records) / len(records) if records else None,
        'frames_with_wrong_identity': sum(bool(set(row['predicted']) - set(row['expected'])) for row in records),
        'average_recognition_ms': statistics.mean(row['ms'] for row in records) if records else None,
        'p95_recognition_ms': sorted(row['ms'] for row in records)[max(0, int(len(records) * .95) - 1)] if records else None,
    }


def evaluate(manifest):
    root = manifest.parent
    data = json.loads(manifest.read_text(encoding='utf-8'))
    references = [dict(item, student_id=str(item['student_id']), path=str((root / item['path']).resolve())) for item in data['references']]
    # A probe is held out: it may never be an enrollment reference under another name.
    enrolled = {Path(item['path']).resolve() for item in references}
    for probe in data['probes']:
        if (root / probe['path']).resolve() in enrolled:
            raise ValueError('Enrollment and evaluation photos must be separate files.')
    report = {'thresholds': {'distance': engine.MAX_MATCH_DISTANCE, 'margin': engine.MIN_RUNNER_UP_MARGIN}, 'modes': {}}
    # Keep evaluation caches isolated from production, and measure each cold enrollment separately.
    with tempfile.TemporaryDirectory() as directory:
        for mode in ('single', 'multi'):
            selected = [item for item in references if mode == 'multi' or item['pose'] == 'front']
            engine.CACHE_PATH = str(Path(directory) / (mode + '.pkl'))
            engine._cached_key = None
            engine._reference_cache = {}
            started = time.perf_counter()
            engine.known_faces(selected)
            enrollment_ms = (time.perf_counter() - started) * 1000
            records = []
            for probe in data['probes']:
                started = time.perf_counter()
                result = engine.recognize(str((root / probe['path']).resolve()), selected)
                records.append({'expected': [str(value) for value in probe['expected_ids']],
                                'predicted': [item['student_id'] for item in result.get('matches', [])],
                                'ms': (time.perf_counter() - started) * 1000,
                                'condition': probe.get('condition', 'unspecified')})
            conditions = defaultdict(list)
            for record in records:
                conditions[record['condition']].append(record)
            report['modes'][mode] = dict(summarize(records), cold_enrollment_ms=enrollment_ms,
                                         by_condition={key: summarize(rows) for key, rows in conditions.items()})
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('manifest', type=Path)
    args = parser.parse_args()
    print(json.dumps(evaluate(args.manifest.resolve()), indent=2))
