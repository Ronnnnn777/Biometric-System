"""Synthetic matching-only latency; not a facial accuracy or end-to-end scanner benchmark."""
import argparse
from pathlib import Path
import statistics
import sys
import time
import numpy as np
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'admin'))
from recognize_face import batch_best_matches


def previous_batch(known, ids, candidates):
    distances = np.linalg.norm(np.asarray(candidates)[:, None, :] - np.asarray(known)[None, :, :], axis=2)
    results = []
    for row in distances:
        students = {}
        for index, distance in enumerate(row):
            student = ids[index]
            students[student] = min(students.get(student, float('inf')), float(distance))
        ranked = sorted(students.items(), key=lambda item: item[1])
        best, distance = ranked[0]
        runner_up = ranked[1][1] if len(ranked) > 1 else float('inf')
        results.append((best, distance) if distance <= .60 and runner_up - distance >= .06 else None)
    return results


if __name__ == '__main__':
    import json
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--students', nargs='+', type=int, default=[100, 1000, 3000])
    parser.add_argument('--iterations', type=int, default=10)
    args=parser.parse_args()
    rng=np.random.default_rng(42)
    report=[]
    for students in args.students:
        # Three views, two lighting variants, eight detected faces.
        known=list(rng.normal(0,.1,(students*6,128)))
        ids=[str(index//6) for index in range(students*6)]
        candidates=list(rng.normal(0,.1,(8,128)))
        result={'students':students,'reference_embeddings':len(known),'faces':8,'synthetic':True}
        for name, function in [('previous_tensor',previous_batch),('updated_matrix',batch_best_matches)]:
            samples=[]
            for _ in range(args.iterations):
                start=time.perf_counter()
                function(known,ids,candidates)
                samples.append((time.perf_counter()-start)*1000)
            result[name+'_average_ms']=statistics.mean(samples)
        report.append(result)
    print(json.dumps(report,indent=2))
