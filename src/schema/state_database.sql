CREATE TABLE IF NOT EXISTS run (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    description TEXT
);

CREATE TABLE IF NOT EXISTS `group` (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    run_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    FOREIGN KEY (run_id) REFERENCES run(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS query (
    digest TEXT NOT NULL,
    group_id INTEGER NOT NULL,
    schema TEXT,
    query_text TEXT,
    impact_description TEXT,
    fix_input TEXT,
    fix_output TEXT,
    query_sample TEXT,
    explain_result TEXT,
    PRIMARY KEY (digest, group_id),
    FOREIGN KEY (group_id) REFERENCES `group`(id) ON DELETE CASCADE
); 