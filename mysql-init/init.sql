-- mysql-init/init.sql
DROP DATABASE IF EXISTS attendance_db;
CREATE DATABASE attendance_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE attendance_db;

-- 建表
CREATE TABLE attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(255) NOT NULL,
  course_id VARCHAR(255) NOT NULL,
  present TINYINT(1) NOT NULL,
  timestamp DATETIME NOT NULL,
  user_name VARCHAR(255) DEFAULT NULL
);

-- （可选）插入几笔测试记录
INSERT INTO attendance (student_id, course_id, present, timestamp, user_name)
  VALUES ('s001', 'demo1', 1, NOW(), '王小明');
