# České instrukce k instalaci GnuCash

Pro běžné používání ve Windows nainstalujte hotový balíček GnuCash. Sestavení místních zdrojových kódů má smysl, pokud chcete program upravovat nebo vyzkoušet konkrétní změny. Níže jsou oba postupy, nastavení češtiny a stručné možnosti pro Linux a macOS.

Datum podkladů: 12. října 2026. Místní zdrojový strom `../gnucash` je na větvi `stable`, commit `b183b507f2db984875d83d31992b2a805f74caea`. Soubor `CMakeLists.txt` uvádí verzi **5.17**. Přítomnost zdrojového stromu sama o sobě neznamená, že je aplikace sestavená a nainstalovaná.

## Instalace ve Windows bez práv správce

Bez administrátorského přístupu použijte **GnuCash Portable** z PortableApps.com. Tento balíček lze rozbalit do vlastní zapisovatelné složky a spouštět bez systémové instalace. Jde o samostatné balení od PortableApps.com; jeho verze může být starší než aktuální oficiální vydání. [GnuCash Portable a podmínky použití](https://portableapps.com/apps/office/gnucash_portable).

1. Na uvedené stránce stáhněte GnuCash Portable.
2. Spusťte stažený balíček běžným způsobem, bez volby „Spustit jako správce“.
3. Jako cílovou složku zvolte například `%LOCALAPPDATA%\Programs\GnuCashPortable`. Nepoužívejte `Program Files`.
4. Po rozbalení spusťte `GnuCashPortable.exe` z cílové složky.
5. Vytvořte a uložte zkušební účetní soubor podle části „Ověření po instalaci“.

PortableApps.com uvádí, že online bankovnictví s některými institucemi vyžaduje spuštění jako správce. Na spravovaném počítači může spuštění přenosných aplikací omezovat také firemní politika; v takovém případě je potřeba součinnost IT.

Běžný oficiální instalátor má v dostupné konfiguraci nastaveno `PrivilegesRequired=poweruser`. Pouhá změna cílového adresáře proto nestačí k instalaci bez zvýšených oprávnění. [Konfigurace oficiálního instalátoru](https://github.com/Gnucash/gnucash-on-windows/blob/master/inno_setup/gnucash-mingw64.iss).

## Instalace hotového programu ve Windows s právy správce

1. Otevřete [oficiální stránku ke stažení](https://www.gnucash.org/download.phtml).
2. Vyberte stabilní vydání pro Microsoft Windows. K datu podkladů stránka nabízí **GnuCash 5.17** pro Windows 8, 10 a 11. Při pozdější instalaci zkontrolujte verzi a podporované systémy na této stránce.
3. Stáhněte instalační soubor `.exe` pomocí odkazu na oficiální stránce.
4. Spusťte instalátor a postupujte podle průvodce. Pokud Windows vyžádá oprávnění správce, potvrďte je pro tuto instalaci.
5. Použijte výchozí instalační adresář, případně si poznamenejte vlastní cestu. Program instalujte odděleně od místních zdrojových kódů v `mikoucto\gnucash`.
6. Po dokončení spusťte GnuCash z nabídky Start a v dialogu o aplikaci ověřte verzi.

Hotový instalační balíček obsahuje potřebné knihovny a dokumentaci. Pro tento postup nepotřebujete kompilátor, CMake ani vývojové balíčky. [Zdroj ke stažení a obsahu balíčků](https://www.gnucash.org/download.phtml).

## Nastavení češtiny ve Windows

GnuCash obvykle převezme regionální nastavení systému. Nastavte český regionální formát a program znovu spusťte. Pokud chcete češtinu nastavit jen pro GnuCash:

1. Ukončete aplikaci.
2. V instalačním adresáři otevřete složku `etc\gnucash`, ve které je soubor `environment`. Cesta může být například `C:\Program Files\gnucash\etc\gnucash` nebo cesta pod `Program Files (x86)`.
3. Vedle souboru `environment` vytvořte textový soubor `environment.local`. Pro zápis do `Program Files` budete potřebovat oprávnění správce. Pokud již existuje, doplňte hodnoty do jeho stávající sekce `[Variables]`.
4. Použijte následující obsah:

```ini
[Variables]
LANG=cs_CZ
LANGUAGE={LANG}
```

5. Ověřte, že soubor nemá skrytou příponu `.txt`, a spusťte aplikaci znovu.

Tento postup vychází z [oficiálních pokynů pro jazyk ve Windows](https://wiki.gnucash.org/wiki/Locale_Settings). Místní strom obsahuje český překlad v `po/cs.po` a české šablony účtů v `data/accounts/cs`. Část textů může zůstat anglicky podle úplnosti překladu.

## Ověření po instalaci

1. Spusťte aplikaci a ověřte, že se zobrazí hlavní okno bez chyby.
2. Vytvořte zkušební účetní soubor. Při nastavení nové účetní knihy zkontrolujte měnu CZK a vyberte vhodnou strukturu účtů.
3. Soubor uložte do vlastní složky s dokumenty, aplikaci zavřete a uložený soubor znovu otevřete.
4. Ověřte české nabídky a formáty dat a čísel.

Při aktualizaci existující instalace si před prvním otevřením účetní knihy v nové verzi vytvořte kopii jejích dat. Instalační složka programu a složka účetních souborů mají odlišný účel.

Volitelné stahování burzovních cen a kurzů vyžaduje Perl a příslušné moduly. Podle místního `README` k jejich instalaci ve Windows slouží položka **Install Online Quotes** ve skupině GnuCash v nabídce Start. Pro běžné vedení účtů tuto funkci nepotřebujete.

## Sestavení místních zdrojových kódů

GnuCash používá CMake a překladač s podporou C++17. Samotné spuštění CMake nezajistí instalaci všech závislostí. Nejprve připravte vývojové prostředí včetně hlavičkových souborů knihoven.

### Potřebné nástroje a knihovny

Požadavky vycházejí z místních souborů `README.dependencies` a `CMakeLists.txt`. Pokud si textové návody odporují s konfigurací, pro tento zdrojový strom rozhoduje konfigurace CMake.

| Součást | Požadavek nebo účel |
| --- | --- |
| Překladač C a C++ | C++17; README uvádí GCC 8 nebo Clang 6 a novější |
| CMake | Nejméně 3.14.5 podle kořenového `CMakeLists.txt` |
| Ninja nebo Make | Nástroj pro vlastní sestavení; Ninja je volitelný |
| Bash, pkg-config, Git | Shell a vyhledávání knihoven; Git při sestavení z repozitáře |
| GLib a související knihovny | GLib nejméně 2.68.1, dále GIO, GObject, GModule a GThread |
| GTK 3 | Nejméně 3.22.30 pro grafickou aplikaci |
| Guile | 3.0, 2.2 nebo nejméně 2.0.9; s podporou regulárních výrazů |
| libxml2, libxslt, xsltproc, zlib | XML, transformace a komprese; libxml2 nejméně 2.9.4 |
| gettext | CMake vyžaduje nejméně 0.19.6; použijte 0.20 nebo novější pro úplné generování překladových podkladů |
| SWIG | Nejméně 3.0.12 podle CMake |
| ICU | Nejméně 54.0 podle CMake |
| Boost | Nejméně 1.67.0, kompatibilní s C++17; požadované komponenty vybírá CMake podle verze Boost |
| WebKitGTK | Ve Windows `webkitgtk-3.0`; mimo Windows `webkit2gtk-4.0` nebo `webkit2gtk-4.1` |
| Google Test a Google Mock | Knihovny pro testy; README uvádí nejméně 1.8.0 |

Volitelné funkce mají v místním CMake tyto výchozí hodnoty:

| Volba | Výchozí hodnota | Dodatečné závislosti |
| --- | --- | --- |
| `WITH_SQL` | `ON` | libdbi a ovladač pro SQLite, MySQL nebo PostgreSQL |
| `WITH_AQBANKING` | `ON` | AqBanking a Gwenhywfar pro online bankovnictví a některé importy |
| `WITH_OFX` | `ON` | libofx pro import OFX a QFX |
| `WITH_PYTHON` | `OFF` | Python a vývojové hlavičky pro Python bindings |

`README.dependencies` doporučuje AqBanking 6.4.0 a Gwenhywfar 5.8.0, zatímco CMake kontroluje nižší minima 6.2.0 a 5.6.0. Pro přípravu prostředí použijte doporučené nebo novější kompatibilní verze. Pokud některou funkci nepotřebujete, můžete ji vypnout pomocí `-D WITH_…=OFF`. Pro češtinu ponechte `DISABLE_NLS=OFF`.

### Sestavení ve Windows

Místní `cmake/README_CMAKE.txt` obsahuje historické kroky s `install.sh`, `custom.sh` a MSYS 1.0. Pro současné prostředí použijte [oficiální repozitář gnucash-on-windows](https://github.com/Gnucash/gnucash-on-windows), který používá MSYS2, MinGW-w64 a jhbuild. Běžné sestavení pomocí Visual Studia místní návod nepodporuje.

1. Z uvedeného repozitáře stáhněte a prostudujte `setup-mingw64.ps1`.
2. V PowerShellu ho spusťte s cílovým adresářem, například:

```powershell
# Spusťte ve složce se staženým skriptem.
.\setup-mingw64.ps1 -target_dir C:\gcdev64
```

3. Po přípravě otevřete MSYS2 MinGW shell odpovídající zvolené architektuře a spusťte:

```bash
cd /c/gcdev64/src/gnucash-on-windows.git
TARGET=gnucash-stable jhbuild -f jhbuildrc build
```

Tento postup sestaví zdroje spravované jhbuild, nikoli automaticky zdejší `mikoucto/gnucash`. Pro sestavení přesně místního stromu je nutné nasměrovat připravené prostředí na jeho kořenový `CMakeLists.txt`. Cesty, architekturu a případné povolení skriptů přizpůsobte aktuálnímu README nástrojů. [Podrobný postup pro Windows](https://github.com/Gnucash/gnucash-on-windows).

### Sestavení na Linuxu nebo v připraveném prostředí macOS

Následující příklad je pro Bash a vyžaduje předem nainstalované závislosti. Spusťte ho v adresáři projektu `mikoucto`, který obsahuje složku `gnucash`. Na macOS je navíc nutné připravit odpovídající GTK a další knihovny podle [platformního návodu](https://wiki.gnucash.org/wiki/Building).

```bash
# Oddělené adresáře pro zdroje, sestavení a výslednou instalaci.
cmake -S ./gnucash -B ./gnucash-build -G Ninja \
  -D CMAKE_BUILD_TYPE=Release \
  -D CMAKE_INSTALL_PREFIX="$HOME/gnucash-local" \
  -D WITH_SQL=OFF \
  -D WITH_AQBANKING=OFF \
  -D WITH_OFX=OFF

cmake --build ./gnucash-build --parallel 4
cmake --build ./gnucash-build --target check
cmake --install ./gnucash-build
"$HOME/gnucash-local/bin/gnucash"
```

Příklad vypíná SQL, AqBanking a OFX, aby omezil dodatečné závislosti. Výsledná aplikace proto nebude mít jejich funkce. Pro úplné sestavení nainstalujte odpovídající knihovny a použijte hodnoty `ON`. Instalace do vlastní domovské složky nevyžaduje `sudo`.

Použijte vnější složku `./gnucash` s kořenovým `CMakeLists.txt`. Vnitřní složka `./gnucash/gnucash` obsahuje jen část aplikace. Pokud jsou knihovny mimo běžné systémové cesty, při konfiguraci doplňte `-D CMAKE_PREFIX_PATH=/cesta/ke/knihovnam`. Podrobnosti jsou v místním [návodu CMake](../gnucash/cmake/README_CMAKE.txt).

## Hotové balíčky pro Linux a macOS

Na Linuxu použijte správce softwaru distribuce a vyhledejte balíček `gnucash`. Alternativou je Flatpak, který obsahuje potřebné knihovny. Na macOS stáhněte balíček pro odpovídající procesor Intel nebo Apple Silicon a verzi systému a postupujte podle distribuce. [Oficiální možnosti instalace](https://www.gnucash.org/download.phtml).

## Řešení běžných potíží při sestavení

- **CMake nenajde knihovnu:** doinstalujte její vývojový balíček a ověřte nastavení `pkg-config`. Samotná knihovna pro běh programu nemusí obsahovat potřebné hlavičky.
- **CMake požaduje příliš starou verzi podle textového návodu:** řiďte se kořenovým `CMakeLists.txt`; například historická zmínka o CMake 3.0 nestačí pro tento strom.
- **Chybí SQL, AqBanking nebo OFX:** doplňte závislosti nebo příslušnou funkci vypněte při konfiguraci.
- **Chybí GTest nebo GMock:** nainstalujte obě knihovny; při použití zdrojového balíčku googletest lze nastavit `GTEST_ROOT` na jeho kořen.
- **Testy selžou:** podrobnosti hledejte v `gnucash-build/Testing/Temporary/LastTest.log`.
- **Aplikace je anglicky:** zkontrolujte locale, instalované překlady a při vlastním sestavení hodnotu `DISABLE_NLS`. Ve Windows zkontrolujte `environment.local`.

## Místní dokumentace a další zdroje

- [README](../gnucash/README) – instalace, spouštění, překlady a platformy.
- [README.dependencies](../gnucash/README.dependencies) – závislosti a jejich verze.
- [Kořenový CMakeLists.txt](../gnucash/CMakeLists.txt) – skutečné požadavky a volby tohoto stromu.
- [README_CMAKE.txt](../gnucash/cmake/README_CMAKE.txt) – konfigurace, sestavení, instalace a testy.
- [HACKING](../gnucash/HACKING) – oddělení sestavení od zdrojů a spuštění při vývoji.
- [doc/README.txt](../gnucash/doc/README.txt) – obsah místní dokumentační složky.
- [Oficiální dokumentace pro uživatele](https://www.gnucash.org/docs.phtml) – příručka aplikace a průvodce účetními koncepty.

Místní složka `doc` obsahuje zejména podklady pro manuálové stránky a příklady. Úplné uživatelské příručky jsou dostupné samostatně na oficiálním webu.
