# Aplikace pro správu akcí
## Zjednodušený přehled
Projekt je webová aplikace pracující s databází, serverovou stranou a klientskou stranou.
Cílem je správa Akcí (např. přednášky, workshopy, školení apod.) v rámci jedné organizace.
Akce prochází různými fázemi od přípravy nápadu po uzavření akce po zadání účetních údajů.
Uživatelé mají různé Role a s nimi svázaná oprávnění k různým operacím.
V rámci atributů Akce je Místo nebo Místa, kde je akce plánovaná a z toho vyplývající kalendář Akcí a Místností a přehled obsazenosti a plánované obsazenosti.
Aplikace informuje uživatele o vybraných událostech pomocí emailu a upozornění ve svém rozhraní.

## Data a databáze
Veškeré tabulky mají sloupec id typu SERIAL.
V rámci databáze se nemažou záznamy pro Osobu, Uživatele, Místo a pod. Při jejich "smazání" se anonymizují např. na "deletedPerson_[id]" a odstraní se pouze jejich kontaktní údaje a další citlivá data.
Účet pro přístup k databázi z webových stránek/aplikace bude mít omezenou možnost volat DELETE pro tyto tabulky.

Struktura dat viz @/database/create.template.sql


## Členění kódu
V kódu je do samostatných modulů vyčleněna jednotlivé funkcionalita, kromě zmíněných modulů jsou zde i běžné moduly pro vykreslení grafiky a pod.:
### Modul pro kontrolu Oprávnění vzhledem k Roli Uživatele
Dostane id uživatele a požadovanou operaci či operace a vrátí, zda tento Uživatel může operaci provést.

### Modul pro komunikaci s databází
Přijímá JS objekty a převádí je do záznamů nebo změn záznamů do databáze.
Převádí záznamy z databáze do JS objektů.


## Provozní funkce
### Průběh Akce
Vytvoření Rozjednané akce - může být pouze nápad, s vyplněním alespoň povinných údajů např. název.
Úprava akce - změna údajů, doplnění nepovinných.
Odvolání akce - ve fázi kdy ještě nebyla potvrzena - přechází do stavu Nedojednaná.
Potvrzení akce - zanesení akce, jako v podstatě jisté - přechází do stavu Potvrzená
Proběhlá akce - jsou doplněny povinné údaje jako návštěvnost apod.
Zrušená akce - pokud byla Potvrzená, ale nakonec neproběhla
Uzavřená akce - Administrátor doplní ekonomické údaje

Archiv akcí: Veškeré akce (Nedojednané, Zrušené i Proběhlé) zůstávají v databázi a jsou viditelné dle specifikace pro Roli

### Změna údajů nebo nastavení
Při změně údajů nebo nastavení se nejprve do formy JS objektu stáhne aktuální nastavení či údaje. Uživatelské změny jsou zaznamenávány do duplicitního JS objektu a uživateli je graficky znázorněno, které položky změnil. Jejich faktická změna v databázi proběhne až při potvrzení kliknutím na tlačítko.

Pokud mají některé položky omezenou délku, umožní políčka vložení libovolně dlouhého textu, ale s upozorněním, že je překročena maximální délka a s grafickým znázorněním (např. barvou pozadí textu), která část textu bude uložena v případě potvrzení.

### Kalendáře
V rámci aplikace budou k dispozici přehledové kalendáře s možností různých filtrů.
Kalendář místnosti, kalendář všech akcí + podle typu akce a data.
Jednotliví uživatelé budou mít možnost vidět "své akce" s rozlišením zda jsou Organizátoři, Zástup

### Události a akce
Archiv všech událostí a akcí je vždy dostupný Administrátorovi a zároveň odpovídající Události a Akce jsou dostupné jeho Organizátorovi.
Události a Akce se zcela vymažou ze systému až na přímou operaci Administrátora.

### Upozornění
Pokud je někdo Organizátor nebo Zástup akce, dostává upozornění v rámci aplikace a také do emailu.
Upozornění se vytváří vždy ve chvíli, kdy k operaci dojde. Upozornění, která jsou navázaná na termín budou mít připravený hlídací soubor, který bude pravidelně spouštěn pomocí CRON.

Aplikace žádná data nemaže, nemění ani nepřidává bez vědomí uživatele. Pokud dochází k libovolné operaci která nevychází z přímé činnosti uživatele, informuje o tom uživatele a čeká až uživatel operaci schválí.

## Nástroje
Aplikace využívá PostgreSQL, PHP, JS, HTML a CSS.
Aplikace bude pod licencí GNU a případné využité knihovny musí být s tímto v souladu.

## Zásady zdrojového kódu
Zdrojový kód se drží pravidla "composition over inheritance";
a také "let data be just data". Každá funkce si tedy zkontroluje, zda data se kterými pracuje jsou ve formátu a obsahu se kterými dokáže pracovat a případě upozorní na nesrovnalost.
Celý zdrojový kód je v anglickém jazyce včetně docstringů
Kód je smysluplně rozdělen do jednotlivých modulů

## Drobné akce
Při anonymizaci upozornit které všechny informace se mažou (a jejich hodnoty) a dát možnost tyto hodnoty uložit/přidat například do poznámky k akci samotné

## Uživatelé
Pokud je v systému pouze jeden aktivní uživatel (neanymizovaný), automaticky má roli Admin a může ostatním přiřazovat role.
Pokud zbývá v systému pouze jeden Admin, nemůže sám sobě tuto roli odstranit. Pokud tedy v systému existuje alespoň jeden aktivní uživatel, vždy existuje alespoň jeden Admin.
Každá role má povolení číst či zapisovat pro konkrétní Operace, tato povolení jsou v databázi.

## Zatím nestrukturované info
Událost se vytvoří automaticky při vytvoření akce - vyžaduje stejné oprávnění jako pro práci s akcí - další akce pak je možné přiřadit v existující události, nebo vytvořit novou akci s novou událostí. Oprávnění jsou teď v pořádku. Doplnil jsem i oprávnění pro stuff.
Vytvoř stránku pro nastavení oprávnění a rolí:
Na stránku bude mít přístup pouze role s oprávněním user.manage.
Na stránce bude možné vytvořit novou roli.
Bude možné zvolit roli a bude vidět přehled všech oprávnění, které role má i nemá. Ty, které má půjde odebrat, ty které nemá půjde přidat. Přehled oprávnění bude ve stále stejném pořadí (například seznamu s přepínačem v každém řádku), aby bylo stejné oprávnění vždy na stejném místě.

Každý uživatel může mít více rolí.
V hlavičce stránky bude vždy vidět zda je přihlášen (jeho jméno, případně email) a vedle toho zobrazená role, pod kterou právě aplikaci prohlíží - po kliknutí na roli si bude moci vybrat z jiné role, kterou má přiřazenou.
Všechny prvky stránky budou vždy viditelné, pokud pro nějaký prvek nemám oprávnění, bude buď odkaz zašedlý (označený jako neaktivní a při najetí na něj se objeví tooltip s informací o nedostatečném oprávnění) Na samotné stránce, pokud její část mohu vidět a část ne, bude v místě které nemohu vidět prostá textová informace o nedostatečném oprávnění, ale nadpis sekce bude vidět vždy.