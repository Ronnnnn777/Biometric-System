# Release verification: advisory, rollover, and facial enrollment

Verified on 30 October 2026 from `improve-teacher-grade-encoding`. The checkout uses the fork `Ronnnnn777/Biometric-System` as `origin` and `jaxy420/Biometric-System` as `upstream`; the pull request targets upstream `main`.

## Change groups

1. Teacher advisory ownership is independent of subject teaching. Active teachers require one advisory; roster/attendance access follows that advisory, while quarterly grade writes require a current-year subject assignment. First-login password changes and archived-account restrictions are enforced server-side.
2. Rollover requires complete annual grades and reviewed inputs, applies the exact honor threshold, balances regular sections within capacity, freezes source students, and records transactional snapshots for safe undo. Advisories stay with their sections across years.
3. Facial enrollment keeps the existing primary image and adds optional pose-specific references through additive migrations, quality validation, protected image delivery, and transactional saves. Existing students do not need re-enrollment.
4. Add/Edit Student share one camera modal. Each pose independently shows Use Camera when empty or Retake Photo when saved/captured/uploaded. Choose Photo remains available; cancellation preserves existing selections. The duplicate external preview panels are removed.
5. Recognition ranks identities across reference embeddings, retains deployed distance/margin rules, caches unchanged descriptors, invalidates changed/deleted/model-specific entries, and uses the same PHP path for webcam and Android attendance.
6. Regression fixtures and technical documentation cover these behaviors without committing private reports or biometric artifacts.

## Checks executed

| Check | Result |
| --- | --- |
| PHP syntax, including migration and fixture scripts | 63 files passed |
| JavaScript syntax | 7 files passed |
| Quarterly grading, roster pagination, and camera/state Node tests | 25 tests passed |
| Promotion/sectioning PHP tests | 29 checks passed |
| Isolated teacher portal HTTP/database integration | 62 checks passed |
| Isolated promotion HTTP/database integration | 25 checks passed |
| Facial enrollment HTTP/form/multipart transport | 14 checks passed |
| Facial reference schema/enrollment/storage | 17 checks passed |
| Tailwind CSS build | Passed; existing outdated Browserslist data notice |
| Git diff whitespace checks | Passed |

Commands:

```text
node --test tests/quarterly-grades.test.cjs tests/student-pagination.test.cjs tests/student-face-camera.test.cjs
C:\xampp\php\php.exe tests/promotion-sectioning.test.php
node tests/teacher-portal.integration.cjs
node tests/promotion.integration.cjs
node tests/student-face-web.test.cjs
C:\xampp\php\php.exe tests/student-face-references.test.php
npm.cmd run build:css
```

Teacher integration checks include all fixture logins, mandatory password changes, unauthorized class/subject submissions, advisory/subject separation, CSRF checks, uniqueness, account creation/restoration rollback, carry-forward, and unchanged live grade fingerprints. Fixture passwords are generated/set only in the disposable test schema; the suite no longer reads the private `Teachers.md` credential report.

Promotion integration checks include read-only previews, incomplete/honor-capacity blocks, stale-preview rejection, injected database failure rollback, deterministic section populations, one promotion per source student, grade/history preservation, undo/rerun, and unchanged live records. Teacher/promotion integration creates randomly named test schemas and private code copies under ignored `tmp/`; it never executes live rollover.

Facial transport checks use synthetic bytes through actual multipart requests and verify the PHP upload fields byte-for-byte. Storage fixtures use a transaction, roll back student/reference changes, and remove only files created by the fixture. These tests verify data routing and persistence, not face recognition accuracy. Face migration reruns preserve existing primary image paths and enforce student/pose/check/foreign-key constraints. Teacher and promotion schema helpers are exercised in isolated database integration; the site-specific teacher-account seeding migration was syntax-checked and reviewed, but was not rerun against live accounts during publication preparation.

## Security review

The commit lists explicitly select source, safe migration/diagnostic scripts, tests, and technical documentation. `.gitignore` excludes `Teachers.md`, `.env` files, logs, uploaded photos, embeddings/pickle files, Python bytecode, private storage, temporary files, and local planning documents. Existing live database configuration is unchanged and is not part of these commits. No private credential report, biometric photo, face descriptor, cache, database export, or per-student audit JSON is included.

Individual live grade values and timestamps were removed from public audit prose. Original reports were retained only under ignored `storage/pre-publication-review`. Aggregate findings and migration/repair procedures remain in the public reports. Synthetic test passwords are fixture-only and do not grant access to live accounts.

## Outstanding validation

No usable scanner Python executable was available on PATH or at the legacy configured location. Python unit tests, Python runtime/compilation checks, recognition service execution, physical webcam/Android tests, FAR/FRR, held-out accuracy comparison, live recognition latency, synthetic Python scale benchmarks, and manual browser theme/layout checks could not run. Configure `BIOMETRIC_PYTHON` with the authorized scanner environment and use the runners documented in `student-face-references.md` before claiming an accuracy or latency improvement. The recognition thresholds were not calibrated or relaxed for additional references.
