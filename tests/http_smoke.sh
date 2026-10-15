#!/usr/bin/env bash
# HTTP-Sicherheits-Smoketest gegen einen laufenden Server: tests/http_smoke.sh http://127.0.0.1:8080
BASE=${1:-http://127.0.0.1:8080}; PASS=0; FAIL=0
J=$(mktemp -d); trap 'rm -rf $J' EXIT
chk() { if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  ✓ $1"; else FAIL=$((FAIL+1)); echo "  ✗ $1 (erwartet $3, erhalten $2)"; fi; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
U1=smoke$RANDOM; U2=smoke$RANDOM
tok() { curl -s -c $J/$1 -b $J/$1 $BASE/api/me | sed 's/.*"csrf":"\([a-f0-9]*\)".*/\1/'; }
T1=$(tok a); T2=$(tok b)
echo "HTTP-Smoketest ($BASE)"
chk "Registrierung ohne CSRF-Token → 403" "$(code -b $J/a -c $J/a -X POST -H 'Content-Type: application/json' -d '{}' $BASE/api/auth/register)" 403
chk "Registrierung mit falschem CSRF-Token → 403" "$(code -b $J/a -c $J/a -X POST -H 'X-CSRF-Token: falsch' -H 'Content-Type: application/json' -d '{}' $BASE/api/auth/register)" 403
for n in a b; do u=$U1; t=$T1; [ $n = b ] && u=$U2 && t=$T2
  curl -s -b $J/$n -c $J/$n -X POST -H "X-CSRF-Token: $t" -H 'Content-Type: application/json' -d "{\"email\":\"$u@example.com\",\"username\":\"$u\",\"password\":\"Passwort-12345\",\"consent\":true}" $BASE/api/auth/register >/dev/null
  r=$(curl -s -b $J/$n -c $J/$n -X POST -H "X-CSRF-Token: $t" -H 'Content-Type: application/json' -d "{\"login\":\"$u\",\"password\":\"Passwort-12345\"}" $BASE/api/auth/login)
  eval "T$([ $n = a ] && echo 1 || echo 2)=$(echo $r | sed 's/.*"csrf":"\([a-f0-9]*\)".*/\1/')"
done
chk "Sitzungsliste ohne Login → 401" "$(code $BASE/api/sessions)" 401
chk "Fallliste öffentlich → 200" "$(code $BASE/api/cases)" 200
SID=$(curl -s -b $J/a -c $J/a -X POST -H "X-CSRF-Token: $T1" -H 'Content-Type: application/json' -d '{"case":"fall-001-hotel","mode":"solo"}' $BASE/api/sessions | sed 's/.*"id":\([0-9]*\).*/\1/')
chk "Sitzung erstellt" "$([ -n "$SID" ] && echo ok)" ok
chk "Eigene Sitzung lesbar" "$(code -b $J/a $BASE/api/sessions/$SID)" 200
chk "IDOR: fremde Sitzung lesen → 404" "$(code -b $J/b $BASE/api/sessions/$SID)" 404
chk "IDOR: fremde Sitzung steuern → 404" "$(code -b $J/b -X POST -H "X-CSRF-Token: $T2" -H 'Content-Type: application/json' -d '{"type":"heartbeat","client_action_id":"idor-test-0001"}' $BASE/api/sessions/$SID/act)" 404
BODY=$(curl -s -b $J/a $BASE/api/sessions/$SID)
for needle in '"solution"' 'key_evidence' 'Alpenglühen' 'Zusatzkarte ausgegeben'; do
  chk "Kein Spoiler im Zustand: $needle" "$(echo "$BODY" | grep -c -- "$needle")" 0; done
chk "Gesperrter Hotspot per API erzwingen → 404" "$(code -b $J/a -X POST -H "X-CSRF-Token: $T1" -H 'Content-Type: application/json' -d '{"type":"inspect","hotspot":"hs_jacket","client_action_id":"force-hotspot-01"}' $BASE/api/sessions/$SID/act)" 404
chk "Admin-API als Spieler → 403" "$(code -b $J/a $BASE/api/admin/cases)" 403
chk "Fall-JSON (Lösung) nicht im Webroot abrufbar" "$(curl -s $BASE/cases/fall-001-hotel.json | grep -c 'key_evidence')" 0
chk "Config nicht abrufbar" "$(curl -s $BASE/config/config.php | grep -c 'pass')" 0
chk "Lösung nicht in /api/cases" "$(curl -s $BASE/api/cases | grep -c 'su_brandt')" 0
chk "Security-Header gesetzt" "$(curl -sI $BASE/api/me | grep -ci 'x-content-type-options: nosniff')" 1
chk "Ungültiges JSON → 400" "$(code -b $J/a -X POST -H "X-CSRF-Token: $T1" -H 'Content-Type: application/json' -d '{kaputt' $BASE/api/sessions)" 400
echo "$PASS bestanden, $FAIL fehlgeschlagen"; [ $FAIL -eq 0 ]
