"""Suppression gate tests for sender_server. Run: python -m unittest -v test_suppression_gate"""

import os
import sys
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sender_server as s

US2 = "18479043315"
TPL = {"name": "etp_welcome_c", "language": "en_US", "variables": 0,
       "firstIsName": False, "components": {}}


class Resp:
    def __init__(self, body):
        self._b = body.encode("utf-8") if isinstance(body, str) else body

    def read(self):
        return self._b

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


class GateTest(unittest.TestCase):
    def setUp(self):
        self.saved = (s.INBOX_URL, s.INBOX_TOKEN)
        s._suppressions.update({"at": 0.0, "numbers": None})
        s.INBOX_URL = "http://inbox.local.test"
        s.INBOX_TOKEN = "tok"
        self.logged = []
        self.p_log = mock.patch.object(s, "append_log",
                                       lambda *a: self.logged.append(a)).start()

    def tearDown(self):
        mock.patch.stopall()
        s.INBOX_URL, s.INBOX_TOKEN = self.saved
        s._suppressions.update({"at": 0.0, "numbers": None})

    def test_opted_out_number_is_refused(self):
        verdict, why = s.check_gates(US2, {US2: "US test 2"}, set(), set(), {US2})
        self.assertEqual(verdict, "refused")
        self.assertIn("suppression", why)

    def test_list_is_cached_for_sixty_seconds(self):
        calls = []

        def fake_urlopen(req, timeout=None):
            calls.append(req)
            return Resp("18479043315")

        with mock.patch.object(s.urllib.request, "urlopen", fake_urlopen):
            s.suppressions()
            s.suppressions()
        self.assertEqual(len(calls), 1, "second call must come from cache")

    def test_unreachable_inbox_blocks_the_send(self):
        def boom(req, timeout=None):
            raise OSError("connection refused")

        with mock.patch.object(s.urllib.request, "urlopen", boom), \
             mock.patch.object(s, "graph", side_effect=AssertionError("graph must not be called")) as g:
            res = s.send_one(TPL, US2, None, None)
        self.assertFalse(res["ok"])
        self.assertEqual(res["status"], "refused")
        self.assertIn("suppression", res["error"])
        self.assertEqual(g.call_count, 0)
        self.assertEqual(self.logged[-1][3], "refused")

    def test_no_inbox_url_configured_also_blocks(self):
        s.INBOX_URL = ""
        with mock.patch.object(s, "graph", side_effect=AssertionError("graph must not be called")):
            res = s.send_one(TPL, US2, None, None)
        self.assertIn("refused", res["status"])
        self.assertIn("suppression", res["error"])

    def test_allowed_number_sends_and_is_recorded(self):
        seen = []

        def fake_urlopen(req, timeout=None):
            seen.append(req)
            return Resp('{"recorded":true}')

        with mock.patch.object(s.urllib.request, "urlopen", fake_urlopen), \
             mock.patch.object(s, "allow_list", lambda: {US2: "US test 2"}), \
             mock.patch.object(s, "unique_in_log", lambda: set()), \
             mock.patch.object(s, "last_24h_unique", lambda: set()), \
             mock.patch.object(s, "graph",
                               lambda *a, **k: {"messages": [{"id": "wamid.ME1"}]}):
            res = s.send_one(TPL, US2, None, None)
        self.assertTrue(res["ok"])
        self.assertEqual(res["message_id"], "wamid.ME1")
        urls = [r.get_full_url() for r in seen]
        self.assertTrue(any(u.endswith("/wa/suppressions") for u in urls), urls)
        self.assertTrue(any(u.endswith("/inbound/record-send") for u in urls), urls)
        # R44: assert the header per request. `{k: v for r in seen for k, v in r.header_items()}` merges
        # both requests into one dict, so if record-send sent no Authorization header at all the value from
        # the suppressions call would satisfy the assertion -- a green test proving the wrong thing.
        for r in seen:
            sent = {k.lower(): v for k, v in r.header_items()}
            self.assertEqual(sent.get("authorization"), "Bearer tok",
                "%s sent without the bearer header" % r.get_full_url())
        self.assertEqual(len(seen), 2, "expected one suppressions read and one record-send write")

    def test_a_record_send_failure_does_not_undo_an_accepted_send(self):
        def fake_urlopen(req, timeout=None):
            if req.get_full_url().endswith("/wa/suppressions"):
                return Resp("")
            raise OSError("inbox is down")

        with mock.patch.object(s.urllib.request, "urlopen", fake_urlopen), \
             mock.patch.object(s, "allow_list", lambda: {US2: "US test 2"}), \
             mock.patch.object(s, "unique_in_log", lambda: set()), \
             mock.patch.object(s, "last_24h_unique", lambda: set()), \
             mock.patch.object(s, "graph",
                               lambda *a, **k: {"messages": [{"id": "wamid.ME2"}]}):
            res = s.send_one(TPL, US2, None, None)
        self.assertTrue(res["ok"], "Meta accepted it; that fact must not be softened")
        self.assertIn("inbox record failed", res["note"])

    def test_the_suppression_list_beats_the_allow_list(self):
        # R44: the number must be on BOTH lists or this proves nothing. Passing an empty `blocked` set --
        # as the first draft did -- exercises only the allow-list branch and would still pass if the
        # suppression check were deleted. Suppression wins: an opted-out contact is not messaged, even one
        # we own a test handset for. Refusing an allow-listed number costs us a warm-up send; sending to a
        # suppressed one costs the account its quality rating, and Meta's opt-out handling is audited.
        verdict, why = s.check_gates(US2, {US2: "US test 2"}, set(), set(), {US2})
        self.assertEqual(verdict, "refused", why)
        self.assertIn("suppression list", why)
        self.assertNotIn("test_numbers.txt", why)