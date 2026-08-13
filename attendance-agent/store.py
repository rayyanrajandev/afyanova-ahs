import sqlite3
import threading
from datetime import datetime
from pathlib import Path


class Store:
    def __init__(self, db_path: str):
        self._lock = threading.Lock()
        self._conn = sqlite3.connect(db_path, check_same_thread=False)
        self._conn.row_factory = sqlite3.Row
        self._conn.execute("PRAGMA journal_mode=WAL")
        self._conn.execute("PRAGMA synchronous=NORMAL")
        self._migrate()

    def _migrate(self):
        self._conn.executescript("""
            CREATE TABLE IF NOT EXISTS attendance_logs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id   TEXT NOT NULL,
                uid         INTEGER NOT NULL,
                user_id     TEXT NOT NULL,
                state       INTEGER NOT NULL,
                type        INTEGER,
                record_time TEXT NOT NULL,
                fingerprint TEXT NOT NULL UNIQUE,
                status      TEXT NOT NULL DEFAULT 'pending',
                created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                sent_at     TEXT,
                error       TEXT
            );

            CREATE INDEX IF NOT EXISTS idx_logs_status ON attendance_logs(status);
            CREATE INDEX IF NOT EXISTS idx_logs_fingerprint ON attendance_logs(fingerprint);

            CREATE TABLE IF NOT EXISTS agent_state (
                key   TEXT PRIMARY KEY,
                value TEXT
            );
        """)
        self._conn.commit()

    def _fingerprint(self, device_id: str, uid: int, record_time: str) -> str:
        return f"{device_id}|{uid}|{record_time}"

    def store_logs(self, device_id: str, logs: list[dict]) -> int:
        stored = 0
        now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
        with self._lock:
            for log in logs:
                fp = self._fingerprint(device_id, log["uid"], log["record_time"])
                try:
                    self._conn.execute(
                        """INSERT OR IGNORE INTO attendance_logs
                           (device_id, uid, user_id, state, type, record_time, fingerprint, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)""",
                        (device_id, log["uid"], log["user_id"],
                         log["state"], log.get("type"), log["record_time"], fp, now),
                    )
                    if self._conn.total_changes > 0:
                        stored += 1
                except sqlite3.IntegrityError:
                    pass
            self._conn.commit()
        return stored

    def get_pending_logs(self, limit: int = 500) -> list[dict]:
        with self._lock:
            rows = self._conn.execute(
                """SELECT id, device_id, uid, user_id, state, type, record_time
                   FROM attendance_logs
                   WHERE status IN ('pending', 'failed')
                   ORDER BY id ASC
                   LIMIT ?""",
                (limit,),
            ).fetchall()
        return [dict(r) for r in rows]

    def count_pending(self) -> int:
        with self._lock:
            row = self._conn.execute(
                "SELECT COUNT(*) AS cnt FROM attendance_logs WHERE status IN ('pending', 'failed')"
            ).fetchone()
        return row["cnt"] if row else 0

    def mark_sent(self, ids: list[int], sent_at: str):
        if not ids:
            return
        placeholders = ",".join("?" for _ in ids)
        with self._lock:
            self._conn.execute(
                f"UPDATE attendance_logs SET status = 'sent', sent_at = ? WHERE id IN ({placeholders})",
                [sent_at] + ids,
            )
            self._conn.commit()

    def mark_failed(self, ids: list[int], error: str):
        if not ids:
            return
        placeholders = ",".join("?" for _ in ids)
        with self._lock:
            self._conn.execute(
                f"UPDATE attendance_logs SET status = 'failed', error = ? WHERE id IN ({placeholders})",
                [error] + ids,
            )
            self._conn.commit()

    def get_latest_record_time(self, device_id: str) -> str | None:
        with self._lock:
            row = self._conn.execute(
                """SELECT record_time FROM attendance_logs
                   WHERE device_id = ? AND status IN ('pending', 'sent')
                   ORDER BY record_time DESC LIMIT 1""",
                (device_id,),
            ).fetchone()
        return row["record_time"] if row else None

    def reset_failed(self):
        with self._lock:
            self._conn.execute(
                "UPDATE attendance_logs SET status = 'pending', error = NULL WHERE status = 'failed'"
            )
            self._conn.commit()

    def get_total_synced(self) -> int:
        with self._lock:
            row = self._conn.execute(
                "SELECT COUNT(*) AS cnt FROM attendance_logs WHERE status = 'sent'"
            ).fetchone()
        return row["cnt"] if row else 0

    def get_state(self, key: str, default: str | None = None) -> str | None:
        with self._lock:
            row = self._conn.execute(
                "SELECT value FROM agent_state WHERE key = ?", (key,)
            ).fetchone()
        return row["value"] if row else default

    def set_state(self, key: str, value: str):
        with self._lock:
            self._conn.execute(
                "INSERT OR REPLACE INTO agent_state (key, value) VALUES (?, ?)",
                (key, value),
            )
            self._conn.commit()

    def close(self):
        self._conn.close()
