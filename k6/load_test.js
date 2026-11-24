import http from 'k6/http';
import { check, group, sleep } from 'k6';

export let options = {
    stages: [
        { duration: "5s", target: 5 },
        { duration: "10s", target: 10 },
        { duration: "10s", target: 50 },
        { duration: "5s", target: 10 },
        { duration: "5s", target: 5 }
    ]
};

const BASE = "http://tpmongo-php:80";

export default function () {
    let ids = [];

    group("Liste des livres", () => {
        const res = http.get(`${BASE}/`);
        check(res, { "liste 200": (r) => r.status === 200 });
        ids = [...res.body.matchAll(/get\.php\?id=([a-f0-9]{24})/g)].map((m) => m[1]);
    });
    sleep(1);
        group("Page donnée de la liste", () => {
        const page = Math.floor(Math.random() * 141) + 1;
        const res = http.get(`${BASE}/?page=${page}`);
        check(res, { "page 200": (r) => r.status === 200 });
    });
    sleep(1);

    if (ids.length > 0) {
        group("Détail d'un livre", () => {
            const id = ids[Math.floor(Math.random() * ids.length)];
            const res = http.get(`${BASE}/get.php?id=${id}`);
            check(res, { "détail 200": (r) => r.status === 200 });
        });
    }
    sleep(1);

    if (Math.random() < 0.1) {
        let newId = null;

        group("Ajout d'un livre", () => {
            const res = http.post(`${BASE}/create.php`, {
                cote: `K6-${__VU}-${__ITER}`,
                titre: `Livre de test k6 ${__VU}-${__ITER}`,
                langue: "français",
                siecle: "21",
                edition: "k6",
            });
            check(res, { "ajout 200": (r) => r.status === 200 });
            const m = res.url.match(/id=([a-f0-9]{24})/);
            newId = m ? m[1] : null;
        });

        if (newId) {
            group("Suppression d'un livre", () => {
                const res = http.get(`${BASE}/delete.php?id=${newId}`);
                check(res, { "suppression 200": (r) => r.status === 200 });
            });
        }
    }
    sleep(1);
}