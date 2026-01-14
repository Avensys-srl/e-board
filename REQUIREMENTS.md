# EBOARD Manager – Descrizione funzionale dettagliata del progetto

## 1. Scopo generale del progetto

EBOARD Manager è una piattaforma software progettata per la **gestione strutturata dell’intero ciclo di vita di progetti elettronici**, con particolare riferimento a schede elettroniche, firmware, accessori e sottosistemi collegati.

Il sistema nasce per:

* centralizzare informazioni tecniche, documentali e decisionali;
* ridurre errori, ambiguità e dipendenze informali;
* garantire tracciabilità completa delle decisioni;
* supportare processi di approvazione multi‑ruolo;
* costituire una base solida per qualità, audit, SAV e sviluppo futuro.

EBOARD Manager non è un semplice repository documentale, ma un **motore di processo** che guida persone, ruoli e stati del progetto.

---

## 2. Ambito di applicazione

Il progetto è pensato per la gestione di:

* schede elettroniche (PCB, PCBA);
* firmware e versioni software;
* accessori elettronici e meccatronici;
* varianti di progetto;
* fornitori e subfornitori;
* test, validazioni, approvazioni;
* storicizzazione tecnica e decisionale.

È applicabile sia a nuovi sviluppi sia a modifiche, retrofit o revisioni di prodotti esistenti.

---

## 3. Architettura generale del sistema

### 3.1 Tech Stack (baseline vincolante)

Il progetto EBOARD Manager è progettato per funzionare su uno stack tecnologico **semplice, stabile e ampiamente supportato**, coerente con ambienti industriali e PMI.

**Backend**

* PHP **7.2.11 compatible** (vincolo di compatibilità)
* Framework: Laravel (versione compatibile con PHP 7.2)

**Database**

* MySQL database

**Ambiente di sviluppo / deploy**

* XAMPP environment (Apache + PHP + MySQL)

**Frontend**

* Bootstrap frontend (default) **oppure** frontend custom equivalente
* **React opzionale**, limitato esclusivamente alle **dashboard** (non obbligatorio per il core system)

Lo stack è volutamente conservativo per garantire:

* facilità di manutenzione;
* ampia reperibilità di competenze;
* riduzione del rischio tecnologico;
* possibilità di evoluzioni progressive senza refactoring radicali.

---

## 4. Concetto chiave: progetto come oggetto strutturato

Ogni progetto gestito da EBOARD Manager è un oggetto complesso che contiene:

* identità univoca;
* stato corrente;
* cronologia degli stati;
* attori coinvolti;
* documenti associati;
* decisioni approvate;
* commenti e richieste;
* versioni tecniche (HW / FW).

Il progetto **non è mai statico**: evolve secondo regole precise.

---

## 5. Gestione dei ruoli

Il sistema è basato su **ruoli ben definiti**, ciascuno con permessi, responsabilità e visibilità differenti.

Esempi di ruoli:

* Electronics Designer
* Firmware Developer
* Supplier
* Test / Validation Lead
* Project Coordinator
* Quality / Approver
* Admin

Ogni ruolo:

* vede solo ciò che è rilevante;
* può compiere solo azioni coerenti con il proprio ruolo;
* riceve notifiche mirate.

---

## 6. Dashboard per ruolo

Ogni utente accede a una **dashboard dedicata**, che mostra:

* progetti assegnati;
* stato di avanzamento;
* azioni richieste;
* approvazioni in attesa;
* notifiche;
* alert di blocco o ritardo.

La dashboard è il punto di lavoro quotidiano.

---

## 7. Stati del progetto

Ogni progetto attraversa una serie di **stati formali**, ad esempio:

* Draft
* Submitted
* Under Review
* Test Requested
* Test Completed
* Approved
* Rejected
* Archived

Gli stati:

* sono finiti e definiti;
* non possono essere saltati arbitrariamente;
* sono accompagnati da regole di ingresso/uscita.

---

## 8. Azioni e transizioni

Il passaggio da uno stato all’altro avviene solo tramite **azioni esplicite**, ad esempio:

* submit
* approve
* request modification
* upload test report
* reject

Ogni azione:

* è associata a uno o più ruoli;
* genera una transizione di stato;
* produce una traccia storica;
* può generare notifiche.

---

## 9. Sistema di notifiche

EBOARD Manager include un sistema di notifiche che:

* informa gli utenti quando è richiesta un’azione;
* segnala cambi di stato;
* evidenzia blocchi o ritardi;
* crea una responsabilità chiara.

Le notifiche sono parte integrante del flusso di lavoro.

---

## 10. Workflow di sottomissione e approvazione

Il cuore del sistema è il **workflow di approvazione**.

Esempio:

1. Designer carica una nuova versione
2. Progetto passa in stato “Submitted”
3. Reviewer riceve notifica
4. Reviewer approva o richiede modifiche
5. Eventuale fase di test
6. Approvazione finale

Ogni passaggio è tracciato.

---

## 11. Gestione documentale

Il sistema permette di allegare:

* schemi elettrici;
* file PCB;
* BOM;
* firmware;
* report di test;
* note tecniche;
* documentazione di fornitore.

Ogni documento è:

* associato a una versione;
* collegato a uno stato;
* storicizzato.

---

## 12. Versioning

EBOARD Manager gestisce:

* versioni di progetto;
* revisioni hardware;
* versioni firmware;
* varianti.

Ogni versione mantiene:

* relazione con le precedenti;
* motivazione della modifica;
* stato di approvazione.

---

## 13. Tracciabilità completa

Il sistema garantisce tracciabilità di:

* chi ha fatto cosa;
* quando;
* perché;
* con quali documenti;
* con quali decisioni.

Questo è fondamentale per:

* qualità;
* audit;
* SAV;
* responsabilità contrattuali.

---

## 14. Dizionario di progetto

EBOARD Manager include un **dizionario condiviso**:

* definizioni tecniche;
* acronimi;
* nomi normalizzati;
* regole comuni.

Serve a evitare ambiguità e interpretazioni personali.

---

## 15. Separazione tra software e contenuti di esempio

Il progetto distingue chiaramente:

* **software EBOARD Manager**;
* **contenuti di esempio** (diagrammi, casi reali, flussi tecnici).

I contenuti di esempio:

* non fanno parte del software;
* servono come riferimento metodologico;
* non devono influenzare lo sviluppo informatico.

---

## 16. Supporto a flussi complessi

Il sistema è pensato per gestire anche:

* logiche stagionali;
* configurazioni alternative;
* soluzioni fool‑proof;
* decisioni progettuali non banali.

Non impone il contenuto tecnico, ma ne governa il processo.

---

## 17. Riduzione errori e costi

EBOARD Manager contribuisce a:

* ridurre errori umani;
* evitare decisioni non documentate;
* prevenire ripetizioni inutili;
* migliorare comunicazione con fornitori;
* ridurre costi indiretti.

---

## 18. Base per evoluzioni future

Il progetto è una base per:

* integrazione con sistemi di test automatici;
* collegamento con sistemi di tracciabilità avanzata;
* interfacce esterne;
* estensione a qualità, produzione, SAV.

---

## 19. Obiettivo finale

L’obiettivo di EBOARD Manager è trasformare la gestione dei progetti elettronici da:

**processo informale e frammentato** → **processo strutturato, tracciabile e ripetibile**.

Questo documento è pensato come **base viva**, da estendere, dettagliare e adattare alle specifiche finali del progetto.
