"""Vérifie l'ajout du planning personnel sur une base de la première version."""

import pathlib, sqlite3, tempfile, subprocess, os

root = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as temp:
    path = temp + "/old.db"
    db = sqlite3.connect(path)
    db.executescript(
        """CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,password TEXT,role TEXT,phone TEXT,active INTEGER,created_at TEXT);
 INSERT INTO users VALUES(1,'Compte existant','old@example.fr','hash-existing','member','',1,'2026-11-01');
 CREATE TABLE workouts(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id),date TEXT NOT NULL,exercise TEXT NOT NULL,sets INTEGER NOT NULL,reps INTEGER NOT NULL,weight REAL NOT NULL,rpe REAL NOT NULL,notes TEXT NOT NULL DEFAULT '');
 INSERT INTO workouts VALUES(1,1,'2026-11-01','Squat',3,10,60,7,'A conserver');"""
    )
    db.commit()
    env = os.environ | {"DATABASE_URL": "sqlite:///" + path, "APP_ENV": "test", "APP_DEBUG": "0"}
    for _ in range(2):
        r = subprocess.run(
            ["php", "bin/console", "app:setup"], cwd=root, env=env, capture_output=True, text=True
        )
        assert r.returncode == 0, r.stdout + r.stderr
    assert "session_id" in [row[1] for row in db.execute("PRAGMA table_info(workouts)")]
    assert db.execute("SELECT COUNT(*) FROM workouts").fetchone()[0] == 1
    assert db.execute("SELECT weight,notes,session_id FROM workouts").fetchone() == (
        60,
        "A conserver",
        None,
    )
    assert db.execute("SELECT password FROM users").fetchone()[0] == "hash-existing"
    assert db.execute("SELECT COUNT(*) FROM training_sessions").fetchone()[0] == 0
    assert not db.execute("PRAGMA foreign_key_check").fetchall()
    db.execute(
        "INSERT INTO training_sessions(user_id,title,starts_at,muscles) VALUES(1,'Séance test','2030-01-01 18:00','[\"chest\"]')"
    )
    sid = db.execute("SELECT last_insert_rowid()").fetchone()[0]
    db.execute(
        "INSERT INTO training_items(session_id,exercise,sets,reps,weight) VALUES(?,'Squat',3,10,60)",
        (sid,),
    )
    db.execute("UPDATE workouts SET session_id=? WHERE id=1", (sid,))
    db.commit()
    r = subprocess.run(
        ["php", "bin/console", "app:user:delete", "old@example.fr"],
        cwd=root,
        env=env,
        input="y\n",
        capture_output=True,
        text=True,
    )
    assert r.returncode == 0, r.stdout + r.stderr
    for table in ["users", "workouts", "training_items", "training_sessions"]:
        assert db.execute("SELECT COUNT(*) FROM " + table).fetchone()[0] == 0
    assert not db.execute("PRAGMA foreign_key_check").fetchall()
print(
    "OK — migration idempotente, ancien carnet conservé, suppression de compte avec séances vérifiée."
)
