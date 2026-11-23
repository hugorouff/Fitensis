"""Parcours HTTP réels sur une base temporaire. Python 3 standard + PHP dans PATH.
Exécution : python tests/smoke.py ; ne modifie pas var/gym.db.
"""

import os, pathlib, tempfile, subprocess, socket, time, urllib.request, urllib.parse, urllib.error, http.cookiejar, re, json, sqlite3, unittest, shutil

ROOT = pathlib.Path(__file__).resolve().parents[1]


class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )
        self.token = ""

    def request(self, path, data=None, method=None, json_body=False, csrf=True):
        headers = {}
        raw = None
        if data is not None:
            if json_body:
                raw = json.dumps(data).encode()
                headers["Content-Type"] = "application/json"
            else:
                data = dict(data)
                if csrf:
                    data["_token"] = self.token
                raw = urllib.parse.urlencode(data).encode()
        if csrf and (method or data is not None):
            headers["X-CSRF-TOKEN"] = self.token
        req = urllib.request.Request(self.base + path, raw, headers, method=method)
        try:
            r = self.opener.open(req, timeout=60)
        except urllib.error.HTTPError as e:
            r = e
        body = r.read().decode("utf-8-sig")
        match = re.search(r'name="(?:_token|csrf-token)" (?:value|content)="([^"]+)"', body)
        if match:
            self.token = match[1]
        return r.status, body, r.geturl()

    def login(self, email, password):
        self.request("/connexion")
        return self.request("/connexion", {"email": email, "password": password})


class Smoke(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        cls.dbpath = cls.tmp.name + "/gym.db"
        env = os.environ | {
            "DATABASE_URL": "sqlite:///" + cls.dbpath,
            "APP_ENV": "test",
            "APP_DEBUG": "0",
            "APP_SECRET": "test-only-secret-value",
        }
        result = subprocess.run(
            ["php", "bin/console", "app:setup", "--demo", "--password=Testing-Gym-2026!"],
            cwd=ROOT,
            env=env,
            capture_output=True,
            text=True,
        )
        if result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        cls.base = f"http://127.0.0.1:{port}"
        cls.log = open(cls.tmp.name + "/server.log", "w+")
        cls.server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", "public", "public/router.php"],
            cwd=ROOT,
            env=env,
            stdout=cls.log,
            stderr=cls.log,
        )
        for _ in range(100):
            try:
                if urllib.request.urlopen(cls.base + "/health", timeout=1).status == 200:
                    break
            except (OSError, urllib.error.URLError):
                time.sleep(0.1)
        cls.admin = Client(cls.base)
        status, body, url = cls.admin.login("admin@gym.local", "Testing-Gym-2026!")
        if status != 200 or "/connexion" in url:
            cls.log.seek(0)
            raise RuntimeError(body[:2000] + cls.log.read())
        cls.member = Client(cls.base)
        cls.member.login("camille@gym.local", "Testing-Gym-2026!")
        cls.coach = Client(cls.base)
        cls.coach.login("coach@gym.local", "Testing-Gym-2026!")

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=10)
        cls.log.close()
        cls.tmp.cleanup()

    def db(self):
        return sqlite3.connect(self.dbpath)

    def test_01_all_pages(self):
        for path in [
            "/",
            "/calendrier",
            "/performances",
            "/espace/workouts",
            "/espace/measurements",
            "/espace/goals",
            "/espace/programs",
            "/espace/equipment",
            "/espace/memberships",
            "/espace/payments",
            "/adherents",
            "/profil",
            "/assistant",
            "/outils",
            "/exercises",
            "/recipes",
        ]:
            with self.subTest(path=path):
                status, body, _ = self.admin.request(path)
                self.assertEqual(status, 200, body[:1200])
                self.assertNotIn("Internal Server Error", body)

    def test_02_authorization(self):
        for path in ["/adherents", "/espace/memberships", "/espace/payments", "/espace/equipment"]:
            self.assertEqual(self.member.request(path)[0], 403)
        self.assertEqual(self.coach.request("/adherents/enregistrer", {"name": "No"})[0], 403)
        self.assertEqual(
            self.member.request("/espace/programs/enregistrer", {"title": "No"})[0], 403
        )
        self.assertEqual(Client(self.base).request("/api/muscles")[0], 401)
        self.assertEqual(self.member.request("/presence", {}, csrf=False)[0], 403)

    def test_03_personal_calendar(self):
        data = {
            "id": "",
            "title": "Ma séance push",
            "date": "2030-02-04",
            "time": "18:00",
            "duration": 60,
            "preset": "push",
            "repeat": 1,
            "exercise": "Développé couché",
        }
        status, body, url = self.member.request("/entrainements/enregistrer", data)
        self.assertEqual(status, 200, body[:1000])
        self.assertIn("/entrainements/", url)
        sid = int(url.rsplit("/", 1)[-1])
        self.assertIn("Ma séance push", body)
        self.assertEqual(self.coach.request("/entrainements/" + str(sid))[0], 404)
        self.assertEqual(
            self.coach.request("/entrainements/" + str(sid) + "/etat", {"status": "completed"})[0],
            404,
        )
        self.assertEqual(
            self.member.request("/entrainements/enregistrer", data, csrf=False)[0], 403
        )
        db = self.db()
        row = db.execute(
            "SELECT user_id,muscles FROM training_sessions WHERE id=?", (sid,)
        ).fetchone()
        self.assertEqual(row[0], 3)
        self.assertIn("chest", row[1])
        db.close()
        self.assertNotIn("Ma séance push", self.coach.request("/calendrier?date=2030-02-04")[1])
        self.assertEqual(self.member.request("/reservations")[0], 404)

    def test_04_planning_and_performance(self):
        data = {
            "title": "Jambes test",
            "date": "2030-03-04",
            "time": "17:30",
            "duration": 60,
            "preset": "lower",
            "repeat": 3,
        }
        status, body, url = self.member.request("/entrainements/enregistrer", data)
        self.assertEqual(status, 200, body[:1000])
        sid = int(url.rsplit("/", 1)[-1])
        db = self.db()
        self.assertEqual(
            db.execute(
                "SELECT COUNT(*) FROM training_sessions WHERE title='Jambes test' AND user_id=3"
            ).fetchone()[0],
            3,
        )
        self.member.request(
            f"/entrainements/{sid}/exercices",
            {"exercise": "Squat séance", "sets": 3, "reps": 10, "weight": 60},
        )
        self.member.request(
            f"/entrainements/{sid}/performances",
            {
                "exercise": "Squat séance",
                "sets": 3,
                "reps": 10,
                "weight": 65,
                "rpe": 8,
                "notes": "Progression",
            },
        )
        log = db.execute(
            "SELECT id,user_id,session_id,weight FROM workouts WHERE exercise=?", ("Squat séance",)
        ).fetchone()
        self.assertEqual(log[1:], (3, sid, 65))
        self.member.request(
            f"/entrainements/{sid}/performances",
            {
                "log_id": log[0],
                "exercise": "Squat séance",
                "sets": 3,
                "reps": 10,
                "weight": 67.5,
                "rpe": 8,
            },
        )
        self.assertEqual(
            db.execute("SELECT weight FROM workouts WHERE id=?", (log[0],)).fetchone()[0], 67.5
        )
        self.assertIn("Squat séance", self.member.request("/performances")[1])
        self.assertNotIn("Squat séance", self.coach.request("/performances")[1])
        self.member.request(f"/entrainements/{sid}/etat", {"status": "completed"})
        self.assertEqual(
            db.execute("SELECT status FROM training_sessions WHERE id=?", (sid,)).fetchone()[0],
            "completed",
        )
        _, _, newurl = self.member.request(f"/entrainements/{sid}/dupliquer", {})
        newid = int(newurl.rsplit("/", 1)[-1])
        self.assertNotEqual(sid, newid)
        self.assertEqual(
            db.execute(
                "SELECT COUNT(*) FROM training_items WHERE session_id=?", (newid,)
            ).fetchone()[0],
            1,
        )
        self.assertEqual(
            db.execute("SELECT COUNT(*) FROM workouts WHERE session_id=?", (newid,)).fetchone()[0],
            0,
        )
        self.member.request(f"/entrainements/{sid}/supprimer", {})
        self.assertEqual(
            db.execute("SELECT COUNT(*) FROM workouts WHERE id=?", (log[0],)).fetchone()[0], 1
        )
        self.assertIsNone(
            db.execute("SELECT session_id FROM workouts WHERE id=?", (log[0],)).fetchone()[0]
        )
        self.assertFalse(db.execute("PRAGMA foreign_key_check").fetchall())
        db.close()

    def test_05_training_privacy(self):
        payload = {
            "date": "2026-11-03",
            "exercise": "Squat",
            "sets": 3,
            "reps": 10,
            "weight": 60,
            "rpe": 7,
            "notes": "Test privé",
        }
        _, body, _ = self.member.request("/espace/workouts/enregistrer", payload)
        self.assertIn("Squat", body)
        db = self.db()
        wid = db.execute("SELECT id FROM workouts WHERE notes='Test privé'").fetchone()[0]
        self.assertNotIn("Test privé", self.coach.request("/espace/workouts")[1])
        self.assertEqual(self.coach.request(f"/espace/workouts/{wid}/supprimer", {})[0], 403)
        invalid = payload | {"sets": -1}
        self.member.request("/espace/workouts/enregistrer", invalid)
        self.assertEqual(
            db.execute(
                "SELECT COUNT(*) FROM workouts WHERE user_id=3 AND notes='Test privé'"
            ).fetchone()[0],
            1,
        )
        self.member.request("/espace/workouts/enregistrer", payload | {"id": wid, "weight": 70})
        self.assertEqual(
            db.execute("SELECT weight FROM workouts WHERE id=?", (wid,)).fetchone()[0], 70
        )
        db.close()

    def test_06_calculators(self):
        status, body, _ = self.member.request(
            "/api/calculate/1rm", {"weight": 100, "reps": 10}, json_body=True
        )
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(body)["estimated_1rm"], 133.3)
        self.assertEqual(
            self.member.request("/api/calculate/1rm", {"weight": -1, "reps": 10}, json_body=True)[
                0
            ],
            422,
        )
        p = {"sex": "male", "age": 30, "weight_kg": 80, "height_cm": 180, "activity_factor": 1.55}
        status, body, _ = self.member.request("/api/nutrition/calculate", p, json_body=True)
        self.assertEqual(status, 200, body)
        self.assertGreater(json.loads(body)["maintenance"], 2000)
        status, body, _ = self.member.request(
            "/api/nutrition/calibrate",
            {
                "average_daily_calories": 2500,
                "start_weight_kg": 80,
                "end_weight_kg": 79,
                "duration_days": 21,
            },
            json_body=True,
        )
        self.assertEqual(status, 200, body)
        status, body, _ = self.member.request(
            "/api/daily-menu?calories=2400&weight=80&goal=maintain"
        )
        self.assertEqual(status, 200, body)
        menu = json.loads(body)
        self.assertEqual(len(menu["meals"]), 4)
        self.assertLess(abs(menu["difference_kcal"]), 180)
        self.assertEqual(self.member.request("/api/daily-menu?calories=-5&goal=maintain")[0], 422)

    def test_07_equipment_finances_goals(self):
        data = {
            "name": "Vélo test",
            "zone": "Cardio",
            "status": "Disponible",
            "next_service": "2027-01-01",
            "notes": "Test",
        }
        self.admin.request("/espace/equipment/enregistrer", data)
        self.assertIn("Vélo test", self.admin.request("/espace/equipment")[1])
        self.member.request(
            "/espace/measurements/enregistrer",
            {"date": "2026-11-03", "weight": 80, "waist": 90, "body_fat": 20, "notes": "Début"},
        )
        self.member.request(
            "/espace/measurements/enregistrer",
            {"date": "2026-11-04", "weight": 79.8, "waist": "", "body_fat": "", "notes": ""},
        )
        self.assertIn("weight-chart", self.member.request("/espace/measurements")[1])
        self.member.request(
            "/espace/goals/enregistrer",
            {
                "title": "Régularité",
                "target": 12,
                "current": 2,
                "unit": "séances",
                "deadline": "2027-01-01",
            },
        )
        self.assertIn("Régularité", self.member.request("/espace/goals")[1])
        self.admin.request(
            "/espace/payments/enregistrer",
            {
                "membership_id": 1,
                "amount": "39.90",
                "paid_on": "2026-11-03",
                "method": "Virement",
                "reference": "TEST",
            },
        )
        self.assertIn("39,90", self.admin.request("/espace/memberships")[1])
        _, body, _ = self.admin.request("/espace/memberships/1/supprimer", {})
        self.assertIn("clôturé", body)

    def test_08_exports_and_profile(self):
        self.assertEqual(self.member.request("/export/workouts.csv")[0], 200)
        self.assertIn("BEGIN:VCALENDAR", self.member.request("/calendrier.ics")[1])
        self.assertEqual(self.member.request("/export/members.csv")[0], 403)
        self.member.request("/profil", {"name": "Camille Test", "phone": "0102030405"})
        self.assertIn("Camille Test", self.member.request("/profil")[1])
        self.member.request("/presence", {})
        self.member.request("/presence", {})
        db = self.db()
        self.assertEqual(
            db.execute("SELECT COUNT(*) FROM checkins WHERE user_id=3").fetchone()[0], 1
        )
        db.close()

    def test_09_custom_exercises(self):
        payload = {
            "name": "Exercice personnel",
            "primary_muscle": "chest",
            "secondary_muscles": ["triceps"],
            "equipment": "Haltères",
            "description": "Test",
        }
        status, body, _ = self.member.request("/api/custom-exercises", payload, json_body=True)
        self.assertEqual(status, 201, body)
        eid = json.loads(body)["data"]["customId"]
        self.assertEqual(
            self.coach.request(f"/api/custom-exercises/{eid}", method="DELETE")[0], 404
        )
        self.assertEqual(
            self.member.request(f"/api/custom-exercises/{eid}", method="DELETE")[0], 200
        )

    def test_10_public_registration(self):
        guest = Client(self.base)
        status, body, url = guest.request("/inscription")
        self.assertEqual(status, 200)
        self.assertIn("Créer mon compte", body)
        data = {
            "name": "Nouveau Sportif",
            "email": "nouveau@example.fr",
            "password": "Mon-MotDePasse-2026",
            "confirmation": "Mon-MotDePasse-2026",
            "role": "admin",
            "active": "0",
        }
        self.assertEqual(guest.request("/inscription", data, csrf=False)[0], 403)
        _, body, _ = guest.request("/inscription", data | {"confirmation": "different"})
        self.assertIn("ne correspondent pas", body)
        _, body, _ = guest.request(
            "/inscription", data | {"password": "court", "confirmation": "court"}
        )
        self.assertIn("12 à 72", body)
        status, body, url = guest.request("/inscription", data)
        self.assertEqual(status, 200)
        self.assertEqual(url, self.base + "/")
        self.assertIn("Bienvenue au club", body)
        db = self.db()
        row = db.execute(
            "SELECT role,active,password FROM users WHERE email=?", (data["email"],)
        ).fetchone()
        self.assertEqual(row[:2], ("member", 1))
        self.assertNotEqual(row[2], data["password"])
        db.close()
        self.assertEqual(guest.request("/adherents")[0], 403)
        self.assertEqual(guest.request("/espace/memberships")[0], 403)
        duplicate = Client(self.base)
        duplicate.request("/inscription")
        _, body, _ = duplicate.request("/inscription", data | {"email": "NOUVEAU@example.fr"})
        self.assertIn("déjà utilisée", body)
        guest.request("/deconnexion", {})
        status, body, url = guest.login(data["email"], data["password"])
        self.assertEqual(status, 200)
        self.assertEqual(url, self.base + "/")

    def test_11_original_home_and_search(self):
        status, body, _ = self.member.request("/")
        self.assertEqual(status, 200)
        self.assertIn("Track your strength.", body)
        self.assertIn("Build your physique.", body)
        self.assertIn('id="weight"', body)
        self.assertIn('id="reps"', body)
        self.assertNotIn("recherche-web", body)
        self.assertNotIn("sidebar", body)
        self.assertEqual(self.member.request("/recherche-web")[0], 404)
        status, body, _ = self.member.request("/espace/workouts?exercise=Developpe%20couche")
        self.assertEqual(status, 200)
        self.assertIn('value="Developpe couche"', body)

    def test_12_shared_navigation(self):
        navs = []
        for path in ["/", "/exercises", "/recipes", "/calendrier", "/performances", "/assistant"]:
            status, body, _ = self.member.request(path)
            self.assertEqual(status, 200)
            match = re.search(r'<header class="site-header">.*?</header>', body, re.S)
            self.assertIsNotNone(match)
            active = re.findall(r'<a href="([^"]+)" class="is-current" aria-current="page">', match.group(0))
            self.assertEqual(len(active), 1)
            self.assertEqual(active[0], '/#outils' if path == '/' else path)
            nav = match.group(0).replace(' class="is-current" aria-current="page"', '')
            navs.append(re.sub(r'\s+<', '<', re.sub(r'>\s+', '>', nav)))
            self.assertEqual(body.count('aria-label="Navigation principale"'), 1)
        self.assertEqual(len(set(navs)), 1)
        _, body, _ = self.member.request("/exercises")
        self.assertIn('class="exercise-page"', body)
        self.assertIn("/assets/exercises-theme.css", body)
        self.assertEqual(body.count('id="createButton"'), 1)


    def test_13_assistant(self):
        self.assertEqual(Client(self.base).request("/api/assistant/status")[0], 401)
        status, body, _ = self.member.request("/assistant")
        self.assertEqual(status, 200)
        self.assertIn("qwen3:4b", body)
        self.assertIn('id="ai-share-training" type="checkbox"', body)
        self.assertEqual(self.member.request("/api/assistant/message", {"message": "Test"}, json_body=True, csrf=False)[0], 403)
        self.assertEqual(self.member.request("/api/assistant/message", {"message": ""}, json_body=True)[0], 422)
        payload = {"answer": "<script>unsafe()</script>", "recipes": [{"title": "Bol test", "minutes": 20, "portions": 2, "ingredients": ["Riz"], "steps": ["Cuire"], "reason": "Test", "catalog_id": None}], "sessions": [{"title": "Séance test", "reason": "Test", "duration_minutes": 45, "muscles": ["chest"], "notes": "Adapter", "exercises": [{"name": "Pompes", "sets": 3, "reps": 10, "weight": None, "rest_seconds": 90, "instructions": "Selon niveau"}]}], "advice": ["Récupérer", "<script>unsafe()</script>"], "follow_up": ""}
        with self.db() as db:
            uid = db.execute("SELECT id FROM users WHERE email='camille@gym.local'").fetchone()[0]
            cur = db.execute("INSERT INTO ai_runs(user_id,question,topic,status,provider,result) VALUES(?,?,'auto','completed','Ollama',?)", (uid, 'Test simulé', json.dumps(payload)))
            run_id = cur.lastrowid
            before = db.execute("SELECT COUNT(*) FROM workouts").fetchone()[0]
        status, body, _ = self.member.request(f"/assistant?run={run_id}")
        self.assertEqual(status, 200, body[:1500])
        self.assertIn("&lt;script&gt;unsafe()&lt;/script&gt;", body)
        self.assertNotIn("<script>unsafe()", body)
        self.assertEqual(self.coach.request(f"/assistant?run={run_id}")[0], 404)
        status, body, url = self.member.request(f"/assistant/{run_id}/seance/0", {"date": "2026-11-05", "time": "18:00"})
        self.assertEqual(status, 200, body[:1000])
        self.assertIn("/entrainements/", url)
        self.assertEqual(self.member.request(f"/assistant/{run_id}/recette/0", {})[0], 200)
        self.assertEqual(self.member.request("/assistant/historique/effacer", {})[0], 200)
        with self.db() as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM workouts").fetchone()[0], before)
            self.assertEqual(db.execute("SELECT COUNT(*) FROM ai_favorites WHERE user_id=?", (uid,)).fetchone()[0], 1)
            self.assertEqual(db.execute("SELECT COUNT(*) FROM training_sessions WHERE user_id=? AND title='Séance test'", (uid,)).fetchone()[0], 1)

    def test_14_session_ideas(self):
        status, body, _ = self.member.request("/assistant")
        self.assertEqual(status, 200)
        self.assertIn('id="ai-ideas-count"', body)
        with self.db() as db:
            uid = db.execute("SELECT id FROM users WHERE email='camille@gym.local'").fetchone()[0]
            proposals = [{"title": f"Idée {i}", "idea_index": i, "reason": "Alternative", "duration_minutes": 30, "muscles": ["chest"], "notes": "Test", "exercises": [{"name": f"Exercice {i}", "sets": 3, "reps": 10, "weight": None, "rest_seconds": 90, "instructions": "Adapter"}]} for i in range(1, 6)]
            payload = {"answer": "Test", "sessions": proposals, "session_ideas": 5, "idea_note": "Cinq alternatives au choix", "recipes": [], "advice": [], "follow_up": ""}
            run = db.execute("INSERT INTO ai_runs(user_id,question,topic,status,provider,result) VALUES(?,'Séances alternatives','sessions','completed','Ollama',?)", (uid, json.dumps(payload))).lastrowid
        status, body, _ = self.member.request(f"/assistant?run={run}")
        self.assertEqual(status, 200, body[:1000])
        self.assertEqual(body.count('class="ai-idea-heading"'), 5)
        for i in range(5):
            self.assertIn(f'/assistant/{run}/seance/{i}', body)
        self.assertEqual(self.coach.request(f"/assistant?run={run}")[0], 404)


    def test_15_excel(self):
        status, body, _ = self.member.request("/performances/excel")
        self.assertEqual(status, 200)
        self.assertIn("Importer mon historique", body)
        self.assertIn('href="/performances" class="is-current"', body)
        self.assertEqual(Client(self.base).request("/performances/excel")[2].split('/')[-1], "connexion")
        exported = self.member.opener.open(self.base + "/performances/excel/modele.xlsx")
        self.assertEqual(exported.status, 200)
        self.assertIn("spreadsheetml.sheet", exported.headers.get("Content-Type"))
        self.assertTrue(exported.read().startswith(b"PK"))
        csv = "Date;Exercice;Séries;Répétitions;Charge (kg);RPE;Notes;user_id\n04/11/2026;Excel HTTP Test;3;10;30,5;;Import HTTP;1\n".encode()
        boundary = "GymExcelBoundaryTest123"
        data = (f"--{boundary}\r\nContent-Disposition: form-data; name=\"default_rpe\"\r\n\r\n8\r\n--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"historique.csv\"\r\nContent-Type: text/csv\r\n\r\n").encode() + csv + f"\r\n--{boundary}--\r\n".encode()
        req = urllib.request.Request(self.base + "/performances/excel/apercu", data, {"Content-Type": "multipart/form-data; boundary=" + boundary, "X-CSRF-TOKEN": self.member.token}, method="POST")
        with self.member.opener.open(req) as response:
            body = response.read().decode()
            self.assertEqual(response.status, 200)
        self.assertIn("Excel HTTP Test", body)
        token = re.search(r'name="import_token"\s+value="([^"]+)"', body).group(1)
        with self.db() as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM workouts WHERE exercise='Excel HTTP Test'").fetchone()[0], 0)
        self.assertEqual(self.member.request("/performances/excel/confirmer", {"import_token": token}, csrf=False)[0], 403)
        self.assertIn("expiré", self.member.request("/performances/excel/confirmer", {"import_token": "wrong"})[1])
        status, body, _ = self.member.request("/performances/excel/confirmer", {"import_token": token})
        self.assertEqual(status, 200)
        with self.db() as db:
            uid = db.execute("SELECT id FROM users WHERE email='camille@gym.local'").fetchone()[0]
            row = db.execute("SELECT user_id,weight,rpe,session_id FROM workouts WHERE exercise='Excel HTTP Test'").fetchone()
            self.assertEqual(row, (uid, 30.5, 8.0, None))
        self.member.request("/performances/excel/confirmer", {"import_token": token})
        with self.db() as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM workouts WHERE exercise='Excel HTTP Test'").fetchone()[0], 1)


    def test_16_recipe_cards_scope(self):
        status, body, _ = self.member.request("/recipes")
        self.assertEqual(status, 200)
        self.assertNotIn('web-recipe-fold', body)
        self.assertNotIn('web-recipe-content', body)



if __name__ == "__main__":
    unittest.main(verbosity=2)
