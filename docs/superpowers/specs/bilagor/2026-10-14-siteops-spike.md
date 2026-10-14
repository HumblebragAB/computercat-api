# SiteOps API: spikresultat (S19)

Körd 2026-10-14 från Forge-servern med Computercats SiteOps-nyckel (scopes `sites:read` + `demo-sites:write`) mot SiteOps main `ec1b29b8`. Engångssajt för `https://e2e-spike.apps.humblebrag.se`, avaktiverad efteråt (id 71).

- `GET /sites?url=<urlkodad>` före skapande: 200 `{"data":[],"meta":{"total":0}}`.
- `POST /sites` med `environment` (`type: demo`, basic auth, `is_monitored: false`): 201. `data.id` = 71, `data.environment` = `{id, site_id, name, type: "demo", url, is_monitored: false, has_basic_auth: true}`, `data.monitoring_enabled: true`. Lösenordet finns inte i svaret.
- Samma `POST` igen: 409 `{"error":"A site with this URL already exists in the team.","site_id":71}`. Uppslaget ger nu `meta.total: 1` och `data[0].id: 71`.
- `GET /sites/71`: `is_active: true`, `monitoring_enabled: true`.
- `GET /sites/71/environments`: en post med `type: demo`, rätt `url`, `is_monitored: false`, `has_basic_auth: true`, inget lösenord.
- `PATCH /sites/71/environments/69` med basic auth och `is_monitored: false`: 200 (ägarbeviset vid adoption). `PATCH … {"is_monitored":true}`: 200 med `data.is_monitored: true`, och listningen visar `true`.
- `POST /sites/71/deactivate`: 200 `{"data":{"id":71,"is_active":false,"monitoring_enabled":false}}`; `GET` visar `is_active: false`.
- `POST /sites` utan `environment`: 422 `{"message":"The environment field is required. (and 1 more error)","errors":{"environment":[…],"environment.type":[…]}}`, och inget skapas (uppslaget ger `total: 0`).
