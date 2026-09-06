-- Run once against the biometric database.
-- Existing administrator accounts and attendance data are preserved.

CREATE TABLE IF NOT EXISTS teachers (
    TEACHER_ID INT NOT NULL AUTO_INCREMENT,
    TEACHER_USER VARCHAR(100) NOT NULL,
    TEACHER_PASS VARCHAR(255) NOT NULL,
    TEACHER_NAME VARCHAR(120) NOT NULL,
    SECTION VARCHAR(20) NOT NULL,
    CREATED_AT TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (TEACHER_ID),
    UNIQUE KEY uq_teacher_user (TEACHER_USER)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Prevent duplicate attendance for the same student on the same day.
-- Review existing duplicate rows before applying this index in production.
-- ALTER TABLE attendance ADD UNIQUE KEY uq_student_day (STUDENT_ID, TIME_IN);
