import argparse
import json
import logging
import random
import sys
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path

import requests
from zk import ZK
from zk.exception import ZKError

from store import Store

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("attendance-agent")

DEFAULT_INTERVAL = 300
DEFAULT_PORT = 4370
DEFAULT_TIMEOUT = 25
DEFAULT_BACKOFF_BASE = 10
DEFAULT_BACKOFF_MAX = 600
HEARTBEAT_EVERY = 6


def load_config(config_path: str) -> dict:
    path = Path(config_path)
    if not path.exists():
        log.error("Config file not found: %s", config_path)
        sys.exit(1)
    with open(path) as f:
        cfg = json.load(f)
    required = ["cloud_api_url", "agent_token", "device_ip"]
    for key in required:
        if key not in cfg:
            log.error("Missing required config key: %s", key)
            sys.exit(1)
    return cfg


def build_device_id(cfg: dict, serial: str | None, name: str | None) -> str:
    return serial or name or cfg["device_ip"]


def connect_device(cfg: dict):
    zk = ZK(
        cfg["device_ip"],
        port=cfg.get("device_port", DEFAULT_PORT),
        timeout=cfg.get("connection_timeout", DEFAULT_TIMEOUT),
        password=cfg.get("device_password", 0),
        force_udp=False,
        ommit_ping=True,
    )
    conn = zk.connect()
    return conn


def fetch_device_info(conn) -> tuple:
    name = conn.get_device_name()
    serial = conn.get_serialnumber()
    model = conn.get_firmware_version()
    return name, serial, model


def detect_device_utc_offset(conn) -> int:
    try:
        device_time = conn.get_time()
        if device_time is None:
            return 0
        if isinstance(device_time, datetime):
            device_dt = device_time
        else:
            device_dt = datetime.strptime(str(device_time), "%Y-%m-%d %H:%M:%S")
        utc_dt = datetime.utcnow()
        offset_minutes = int((device_dt - utc_dt).total_seconds() / 60)
        if abs(offset_minutes) > 1:
            log.info("Device UTC offset: %+d min", offset_minutes)
            return offset_minutes
    except ZKError:
        pass
    return 0


def to_utc(record_time: str, offset_minutes: int) -> str:
    if offset_minutes == 0:
        return record_time
    dt = datetime.strptime(record_time, "%Y-%m-%d %H:%M:%S") - timedelta(minutes=offset_minutes)
    return dt.strftime("%Y-%m-%d %H:%M:%S")


def fetch_device_users(conn) -> dict:
    mapping = {}
    try:
        users = conn.get_users()
        for u in users:
            uid = str(u.user_id)
            name = (u.name or "").strip()
            if name:
                mapping[uid] = name
    except ZKError:
        log.warning("Failed to fetch device users")
    return mapping


def fetch_all_attendances(conn, user_names: dict | None = None, utc_offset: int = 0) -> list[dict]:
    logs = []
    attendances = conn.get_attendance()
    for a in attendances:
        if not a.timestamp:
            continue
        uid = str(a.user_id)
        record_time = a.timestamp.strftime("%Y-%m-%d %H:%M:%S")
        record_time = to_utc(record_time, utc_offset)
        logs.append({
            "uid": a.uid,
            "user_id": uid,
            "device_user_name": (user_names or {}).get(uid),
            "state": a.status,
            "type": a.punch,
            "record_time": record_time,
        })
    return logs


def send_to_cloud(payload: dict, cfg: dict) -> dict | None:
    url = cfg["cloud_api_url"].rstrip("/") + "/v1/attendance/agent/push-logs"
    token = cfg["agent_token"]
    timeout = cfg.get("api_timeout", 60)

    try:
        resp = requests.post(
            url,
            json=payload,
            headers={
                "X-Agent-Token": token,
                "Content-Type": "application/json",
                "Accept": "application/json",
            },
            timeout=timeout,
        )

        if resp.status_code == 200:
            return resp.json()
        elif resp.status_code == 401:
            log.error("Authentication failed. Check ATTENDANCE_AGENT_TOKEN on server.")
            return None
        else:
            log.warning("API error %d: %s", resp.status_code, resp.text[:300])
            return None

    except requests.exceptions.ConnectionError:
        log.warning("Cannot reach cloud API (connection error)")
        return None
    except requests.exceptions.Timeout:
        log.warning("Cloud API timed out after %ds", timeout)
        return None
    except requests.exceptions.RequestException as e:
        log.warning("API request failed: %s", e)
        return None


def send_heartbeat(cfg: dict, store: Store, device_serial: str, device_name: str, user_names: dict | None = None):
    url = cfg["cloud_api_url"].rstrip("/") + "/v1/attendance/agent/heartbeat"
    token = cfg["agent_token"]
    timeout = cfg.get("api_timeout", 10)

    payload = {
        "device_serial": device_serial,
        "device_name": device_name,
        "last_sync_at": store.get_latest_record_time(device_serial or device_name),
        "pending_count": store.count_pending(),
    }
    if user_names:
        payload["users"] = user_names

    try:
        resp = requests.post(
            url,
            json=payload,
            headers={"X-Agent-Token": token, "Content-Type": "application/json"},
            timeout=timeout,
        )
        if resp.status_code == 200:
            log.debug("Heartbeat sent")
            return True
    except requests.exceptions.RequestException:
        log.debug("Heartbeat failed (non-critical)")
    return False


def sync_device(cfg: dict, store: Store, device_serial: str, device_name: str, device_id: str, user_names: dict | None = None, utc_offset: int = 0) -> dict:
    conn = connect_device(cfg)

    try:
        conn.disable_device()
    except ZKError:
        pass

    logs = fetch_all_attendances(conn, user_names, utc_offset)
    log.info("Device has %d total records", len(logs))

    stored = store.store_logs(device_id, logs)
    if stored:
        log.info("Stored %d new records locally", stored)

    BATCH_SIZE = 100

    try:
        conn.disconnect()
    except Exception:
        pass

    total_synced = 0
    total_skipped = 0

    while True:
        batch = store.get_pending_logs(limit=BATCH_SIZE)
        if not batch:
            break

        log.info("Sending %d records to cloud...", len(batch))
        payload = {
            "device_id": device_id,
            "device_name": device_name,
            "device_ip": cfg["device_ip"],
            "device_serial": device_serial,
            "device_model": None,
            "pulled_at": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
            "users": {uid: name for uid, name in user_names.items()},
            "logs": [
                {
                    "uid": r["uid"],
                    "user_id": r["user_id"],
                    "device_user_name": (user_names or {}).get(r["user_id"]),
                    "state": r["state"],
                    "type": r["type"],
                    "record_time": r["record_time"],
                }
                for r in batch
            ],
        }

        result = send_to_cloud(payload, cfg)
        if result is None:
            store.mark_failed([r["id"] for r in batch], "cloud_error")
            return {"synced": total_synced, "stored": stored, "pending": store.count_pending(), "status": "send_failed"}

        sent_at = result.get("server_time") or datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        store.mark_sent([r["id"] for r in batch], sent_at)

        synced = result.get("synced", len(batch))
        skipped = result.get("skipped", 0)
        total_synced += synced
        total_skipped += skipped
        log.info("Batch done: synced %d, skipped %d", synced, skipped)

    log.info("All done: synced %d, skipped %d", total_synced, total_skipped)
    return {
        "synced": total_synced,
        "skipped": total_skipped,
        "stored": stored,
        "total_synced": store.get_total_synced(),
        "pending": store.count_pending(),
        "status": "ok",
    }


def run_cycle(cfg: dict, store: Store, device_serial: list, device_name: list, device_id: list, cycle_count: list) -> dict:
    result = {"status": "ok", "heartbeat_sent": False}

    try:
        conn = connect_device(cfg)
    except ZKError as e:
        log.warning("Device connection failed: %s", e)
        return {"status": "device_unreachable"}

    user_names = None
    try:
        name, serial, model = fetch_device_info(conn)
        if serial:
            device_serial[0] = serial
        if name:
            device_name[0] = name
        device_id[0] = build_device_id(cfg, device_serial[0], device_name[0])

        user_names = fetch_device_users(conn)
        if user_names:
            log.info("Fetched %d user names from device", len(user_names))

        utc_offset = detect_device_utc_offset(conn)

        device_result = sync_device(cfg, store, device_serial[0], device_name[0], device_id[0], user_names, utc_offset)
        result.update(device_result)

    except ZKError as e:
        log.warning("Device error during sync: %s", e)
        result["status"] = "device_error"
    except Exception as e:
        log.exception("Unexpected error during sync: %s", e)
        result["status"] = "sync_error"
    finally:
        try:
            conn.disconnect()
        except Exception:
            pass

    cycle_count[0] += 1
    if cycle_count[0] % HEARTBEAT_EVERY == 0:
        send_heartbeat(cfg, store, device_serial[0], device_name[0], user_names)
        result["heartbeat_sent"] = True

    return result


def main():
    parser = argparse.ArgumentParser(description="ZKTeco Attendance Agent")
    parser.add_argument("-c", "--config", default="config.json", help="Config file path")
    parser.add_argument("-i", "--interval", type=int, default=DEFAULT_INTERVAL, help="Polling interval (seconds)")
    parser.add_argument("--once", action="store_true", help="Run once and exit")
    parser.add_argument("--log-file", help="Path to log file")
    parser.add_argument("--db", default="agent.db", help="SQLite database path")
    args = parser.parse_args()

    if args.log_file:
        fh = logging.FileHandler(args.log_file)
        fh.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(message)s"))
        log.addHandler(fh)

    cfg = load_config(args.config)
    interval = cfg.get("interval", args.interval)
    store = Store(args.db)
    store.reset_failed()

    device_serial = [store.get_state("device_serial") or ""]
    device_name = [store.get_state("device_name") or ""]
    device_id = [store.get_state("device_id") or ""]
    cycle_count = [0]
    backoff = cfg.get("backoff_base", DEFAULT_BACKOFF_BASE)
    backoff_max = cfg.get("backoff_max", DEFAULT_BACKOFF_MAX)

    log.info("Attendance Agent started")
    log.info("Device: %s:%s", cfg["device_ip"], cfg.get("device_port", DEFAULT_PORT))
    log.info("Cloud: %s", cfg["cloud_api_url"])
    log.info("Interval: %ds  DB: %s", interval, args.db)

    if args.once:
        result = run_cycle(cfg, store, device_serial, device_name, device_id, cycle_count)
        print(json.dumps(result, indent=2))
        store.close()
        sys.exit(0 if result.get("status") == "ok" else 1)

    consecutive_failures = 0

    while True:
        result = run_cycle(cfg, store, device_serial, device_name, device_id, cycle_count)

        if result.get("status") in ("device_unreachable", "device_error", "send_failed"):
            consecutive_failures += 1
            wait = backoff * (2 ** min(consecutive_failures - 1, 6))
            wait = min(wait, backoff_max)
            jitter = random.uniform(0, wait * 0.1)
            wait = wait + jitter
            log.warning("Sync failed (%s), retrying in %.0fs (failure #%d)",
                        result["status"], wait, consecutive_failures)
        else:
            consecutive_failures = 0
            if result.get("status") != "up_to_date":
                log.info("Sync OK — synced=%(synced)d stored=%(stored)d pending=%(pending)d",
                         result)
            wait = float(interval)

        if device_serial[0]:
            store.set_state("device_serial", device_serial[0])
        if device_name[0]:
            store.set_state("device_name", device_name[0])
        if device_id[0]:
            store.set_state("device_id", device_id[0])

        time.sleep(wait)


if __name__ == "__main__":
    main()
