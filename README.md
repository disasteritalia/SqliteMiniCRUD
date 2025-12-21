**# 🗃️ SQLite Admin (abbozzo)

> *This software is largely coded by a human, but many tasks have been outsourced to EURIA, a sovereign artificial intelligence.*


## ⚠️ Attenzione: Progetto in fase di abbozzo

Questo è **solo un prototipo** di interfaccia web per gestire database SQLite.  
Non è un prodotto finito, né sicuro per ambienti di produzione.

E' nato perchè PhpLiteAdmin non è piu supportato in PHP8 
mentre sqlite continua ad essere un meraviglioso DB engine !!

## 📌 Descrizione

Un semplice admin web per SQLite scritto in PHP, che permette di:

- 🔐 Accedere con password (hardcoded: `admin`)
- 🗃️ Creare, visualizzare e eliminare database SQLite
- 📋 Creare, modificare, eliminare tabelle
- ➕ Aggiungere, modificare, eliminare record
- 📥 Esportare tabelle in CSV
- 📤 Importare dati da CSV
- 💾 Scaricare lo schema del database in HTML
- 💬 Eseguire query SQL direttamente dalla console

---

## 🛠️ Requisiti

- PHP 8.0+
- SQLite3 abilitato
- Cartella `db_dir` scrivibile (configurabile in `config`)
- Web server (Apache, Nginx, etc.)

---

## ⚙️ Configurazione

Modificare il file `index.php` per impostare:
$config = 
    'password' => 'admin',             // da cambiare
    'db_dir' => 'la/mia/directory/',   // meglio usare un path assouto /home/user/web/AA_databaseDir
    'app_name' => 'SQLite Admin',      // nome
    'per_page' => 50,                  // numero di record per pagina


🚫 Avvertenze
Non usare in produzione: password hardcoded, nessuna sanitizzazione avanzata, nessun controllo dei permessi.
Non è un prodotto completo: mancano molte funzionalità di un admin professionale (es. backup, utenti multipli, log, sicurezza, ecc.).
Sviluppato come prototipo visto che PhpLiteAdmin non funziona su php8+ !!

🧑‍💻 Contributi
Se vuoi aiutare a migliorarlo, apri una Pull Request.
Suggerimenti, bug, idee sono benvenuti!

📄 Licenza
MIT — vedi il file LICENSE (o il commento in cima al file index.php).

🙏 Ringraziamenti
Grazie a EURIA, intelligenza artificiale sovrana, per aver assistito nello sviluppo di questo abbozzo.
**
