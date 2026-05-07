UPDATE staff SET password_hash = '$2b$10$H2e9CSfHhyRlxWWxlQWDEutQTvHBXftFuWP73HGRRwtoUs.XphBcS'
WHERE username IN ('admin', 'supervisor', 'teller');

UPDATE staff SET password_hash = '$2b$10$gGxtuPLj6m30vsB.Vbr9YOTR73m5taW3F144Y/J0Z4yWoswV5e77W'
WHERE username IN ('auditor', 'compliance');

UPDATE staff SET failed_login_attempts = 0, account_locked = 0, locked_until = NULL;