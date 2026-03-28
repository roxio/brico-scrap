# Bricoman.pl Product Scraper

Skrypt PHP do generowania kart technicznych produktów ze strony Bricoman.pl na podstawie numerów referencyjnych. Generuje plik HTML z gotowymi kartami technicznymi.

## ✨ Funkcjonalności
- Pobieranie danych o produkcie z sitemap.
- Wydobywanie:
  - nazwy produktu,
  - numeru referencyjnego,
  - zdjęcia produktu,
  - logotypu producenta,
  - cech technicznych,
  - piktogramów,
  - generowanie kodu kreskowego.
- Generowanie pliku HTML z kartami produktów:
  - sekcje: nazwa, zdjęcie, cechy techniczne, piktogramy, logo producenta,
  - kod kreskowy dla numeru referencyjnego,
  - data i godzina wydruku.

## 📂 Struktura pliku
Cały skrypt znajduje się w pliku **`index.php`** i składa się z dwóch głównych części:

1. **Klasa `BricomanProductScraper`** – odpowiedzialna za:
   - wyszukiwanie produktów po numerze referencyjnym,
   - pobieranie i parsowanie danych,
   - generowanie kodu HTML kart produktowych.

2. **Interfejs użytkownika (formularz HTML)** – umożliwia:
   - wprowadzenie wielu numerów referencyjnych (oddzielonych przecinkami, spacjami lub nowymi liniami),
   - uruchomienie procesu generowania kart,
   - pobranie gotowego pliku HTML.

## 🚀 Jak uruchomić
1. Skopiuj plik `index.php` na serwer z obsługą PHP (np. Apache, Nginx, XAMPP).
2. Otwórz stronę w przeglądarce (np. `http://localhost/index.php`).
3. W formularzu wpisz numery referencyjne produktów Bricoman.
4. Kliknij **„Generuj karty”**.
5. Pobierz wygenerowany plik HTML i otwórz go w przeglądarce lub przekonwertuj do PDF.

## 🖼️ Przykład działania
- Użytkownik podaje numery referencyjne:
  ```
  20376895
  20893985
  25085205
  ```
- Skrypt generuje plik: `products_YYYY-MM-DD_HH-MM-SS.html`
- Każda karta zawiera:
  - nazwę i zdjęcie produktu,
  - tabelę cech technicznych,
  - logotyp marki,
  - zestaw piktogramów,
  - kod kreskowy numeru referencyjnego.

## ⚙️ Wymagania
- PHP 7.4 lub nowsze
- Dostęp do internetu (skrypt pobiera dane ze strony www dostawcy)

## 📌 Uwagi
- Skrypt robi małą przerwę (`usleep(500000)`) między pobieraniem kolejnych produktów, aby nie przeciążać serwera.
- Jeśli nie znajdzie produktu, zwraca komunikat błędu dla konkretnego numeru referencyjnego.

## 📄 Licencja
Projekt do użytku własnego / edukacyjnego. W przypadku wykorzystania komercyjnego upewnij się, że posiadasz zgodę na scrapowanie danych z serwisu Bricoman.



# Bricoman.pl Product Scraper

A PHP script for fetching product datasheets from Bricoman.pl based on reference numbers. It generates an HTML file with ready-made product cards.

## ✨ Features
- Fetch product data from the Bricoman sitemap.
- Extracts:
  - product name,
  - main reference number (SKU),
  - product image,
  - manufacturer logo,
  - technical features (attributes),
  - pictograms,
  - barcode.
- Generates an HTML file with product cards:
  - 2 cards per A4 page (landscape layout),
  - sections: name, image, technical features, pictograms, manufacturer logo,
  - barcode for the reference number,
  - print date and time.

## 💾 File Structure
The entire script is in **`index.php`** and consists of two main parts:

1. **`BricomanProductScraper` class** – responsible for:
   - searching products by reference number,
   - fetching and parsing data,
   - generating HTML code for product cards.

2. **User interface (HTML form)** – allows:
   - entering multiple reference numbers (separated by commas, spaces, or new lines),
   - running the card generation process,
   - downloading the resulting HTML file.

## 🚀 How to Run
1. Copy the `index.php` file to a PHP-enabled server (e.g., Apache, Nginx, XAMPP).  
2. Open the page in a browser (e.g., `http://localhost/index.php`).  
3. Enter Bricoman product reference numbers in the form.  
4. Click **“Generate Cards”**.  
5. Download the generated HTML file and open it in a browser or convert it to PDF.

## 🖼️ Example Usage
- User inputs reference numbers:
  ```
  20376895
  20893985
  25085205
  ```
- The script generates a file: `products_YYYY-MM-DD_HH-MM-SS.html`  
- Each card includes:
  - product name and image,
  - a table of technical features,
  - brand logo,
  - set of pictograms,
  - barcode of the reference number.

## ⚙️ Requirements
- PHP 7.4 or higher  
- Internet access (the script fetches data from website)

## 📌 Notes
- The script includes a small delay (`usleep(500000)`) between fetching products to avoid overloading the server.  
- If a product is not found, it returns an error message for that specific reference number.

## 📜 License
For personal/educational use only. For commercial use, ensure you have permission to scrape data from the Bricoman website.

