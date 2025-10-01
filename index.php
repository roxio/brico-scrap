<?php
class BricomanProductScraper {
    private $base_url = "https://www.bricoman.pl";
    private $sitemap_urls = [
        "https://www.bricoman.pl/media/sitemap/products-1-1.xml",
        "https://www.bricoman.pl/media/sitemap/products-1-2.xml"
    ];
    private $pictograms = [];
    
    // Lista cech do wykluczenia
    private $excluded_features = [
        'Kraj odpowiedzialnego podmiotu gospodarczego produktu w UE',
        'Głębokość transport',
        'Wysokość transport',
        'Szerokość transport',
        'Rodzina kolorów',
        'Kolor rodzina',
        'Kod dostawcy',
        'Referencja dostawcy',
		'Styl płytek',
		'Rektyfikacja [tak/nie]',
		'Grupa wymiarowa',
		'Funkcja antypoślizgowa',
		'Odporność na zużycie',
		'Kolor',
		'Gama kolorystyczna'
        // Tutaj możesz dodać więcej cech do wykluczenia
        // Wystarczy dopisać nową linię z nazwą cechy
    ];
    
    public function findProductByReference($reference_number) {
        foreach ($this->sitemap_urls as $sitemap_url) {
            try {
                $product_url = $this->searchInSitemap($sitemap_url, $reference_number);
                if ($product_url) {
                    return $product_url;
                }
            } catch (Exception $e) {
                error_log("Błąd przy przeszukiwaniu sitemap: " . $e->getMessage());
            }
        }
        return null;
    }
    
    private function searchInSitemap($sitemap_url, $reference_number) {
        $xml_content = $this->makeRequest($sitemap_url);
        if (!$xml_content) {
            return null;
        }
        
        $pattern = '/<loc>(.*?' . preg_quote($reference_number, '/') . '.*?)<\/loc>/';
        if (preg_match_all($pattern, $xml_content, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, $reference_number) !== false) {
                    return trim($url);
                }
            }
        }
        return null;
    }
    
    public function getProductData($product_url, $reference_number) {
        try {
            $html = $this->makeRequest($product_url);
            if (!$html) {
                return ["error" => "Nie udało się pobrać strony produktu"];
            }
            
            return $this->parseProductPage($html, $product_url, $reference_number);
            
        } catch (Exception $e) {
            return ["error" => "Błąd przy pobieraniu danych: " . $e->getMessage()];
        }
    }
    
    private function makeRequest($url) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n" .
                           "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
                           "Accept-Language: pl-PL,pl;q=0.9,en-US;q=0.8,en;q=0.7\r\n",
                'timeout' => 15,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new Exception("HTTP request failed");
        }
        return $response;
    }
    
    private function parseProductPage($html, $product_url, $reference_number) {
        $data = [
            'title' => ['Produkt Bricoman'],
            'main_sku' => [$reference_number],
            'product_picture' => null,
            'product_brand' => null,
            'attributes_list_object' => $this->extractTechnicalSpecifications($html),
            'pictograms' => $this->pictograms,
            'print_date' => date('d.m.Y'),
            'print_hour' => date('H:i')
        ];
        
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $html, $match)) {
            $data['title'][0] = strip_tags(trim($match[1]));
        }
        
        // Najpierw próbujemy znaleźć obraz przez metodę _picture.jpeg
        $data['product_picture'] = $this->extractProductPicture($html, $reference_number);
        
        // Jeśli nie znaleziono przez _picture.jpeg, używamy starych metod
        if (!$data['product_picture']) {
            $image_patterns = [
                '/<img[^>]*class="[^"]*b-product-carousel__main-slide swiper-slide[^"]*"[^>]*src="([^"]*\.(jpg|jpeg|png|gif))"[^>]*>/i',
                '/<img[^>]*class="[^"]*b-product-carousel__main-slide swiper-slide[^"]*"[^>]*data-src="([^"]*\.(jpg|jpeg|png|gif))"[^>]*>/i',
                '/<div[^>]*class="[^"]*b-product-carousel__main-slide-image[^"]*"[^>]*>.*?<img[^>]*src="([^"]*\.(jpg|jpeg|png|gif))"[^>]*>/is'
            ];
            
            foreach ($image_patterns as $pattern) {
                if (preg_match($pattern, $html, $match)) {
                    $data['product_picture'] = $this->normalizeUrl($match[1]);
                    break;
                }
            }
        }
        
        $brand_patterns = [
            '/<img[^>]*class="[^"]*b-product-carousel__main-brand-image[^"]*"[^>]*src="([^"]*)"[^>]*>/i',
            '/<img[^>]*class="[^"]*b-product-carousel__main-brand-image[^"]*"[^>]*data-src="([^"]*)"[^>]*>/i',
            '/<div[^>]*class="[^"]*b-product-carousel__main-brand[^"]*"[^>]*>.*?<img[^>]*src="([^"]*)"[^>]*>/is'
        ];
        
        foreach ($brand_patterns as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                $data['product_brand'] = $this->normalizeUrl($match[1]);
                break;
            }
        }
        
        $this->extractPictogramsFromAccordion($html);
        $data['pictograms'] = $this->pictograms;
        
        return $data;
    }

     private function extractProductPicture($html, $reference_number) {
        // Szukamy bezpośrednio w HTML obrazów z _picture.jpeg
        $pattern = '/<img[^>]*(?:src|data-src)="([^"]*' . preg_quote($reference_number, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i';
        
        if (preg_match($pattern, $html, $match)) {
            $url = $this->cleanImageUrl($match[1]);
            return $this->normalizeUrl($url);
        }
        
        // Alternatywnie szukamy w data-src lub innych
        $patterns = [
            '/<img[^>]*data-src="([^"]*' . preg_quote($reference_number, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i',
            '/<img[^>]*src="([^"]*' . preg_quote($reference_number, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i',
            '/<div[^>]*data-image="([^"]*' . preg_quote($reference_number, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                $url = $this->cleanImageUrl($match[1]);
                $image_url = $this->normalizeUrl($url);
                if ($this->checkImageExists($image_url)) {
                    return $image_url;
                }
            }
        }
        
        return null;
    }

    private function cleanImageUrl($url) {
        // Usuwa wszystko po .jpeg (włącznie z parametrami zapytania)
        if (strpos($url, '.jpeg?') !== false) {
            $url = substr($url, 0, strpos($url, '.jpeg?') + 5); // +5 aby zachować ".jpeg"
        }
        // Alternatywnie dla .jpg
        if (strpos($url, '.jpg?') !== false) {
            $url = substr($url, 0, strpos($url, '.jpg?') + 4); // +4 aby zachować ".jpg"
        }
        return $url;
    }

    private function checkImageExists($url) {
        return true;
        
    }

    private function normalizeUrl($url) {
        if (!$url) return null;
        if (strpos($url, 'http') === 0) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        return $this->base_url . $url;
    }
    
    private function extractPictogramsFromAccordion($html) {
        $pictograms = [];
    
        if (preg_match('/<m-accordion[^>]*class="[^"]*b-product-details__accordion[^"]*"[^>]*>(.*?)<\/m-accordion>/is', $html, $accordion_match)) {
            $accordion_section = $accordion_match[1];
    
            if (preg_match_all('/<img[^>]*(?:src|data-src)="([^"]*(_picto)?\.(jpg|jpeg|png|svg)(\?[^"]*)?)"[^>]*>/i', $accordion_section, $img_matches)) {
                foreach ($img_matches[1] as $img_url) {
                    $normalized = $this->normalizeUrl($img_url);
                    if ($normalized) {
                        $pictograms[] = $normalized;
                    }
                }
            }
        }
    
        if (preg_match_all('/<img[^>]*(?:src|data-src)="([^"]*?_picto\.(jpg|jpeg|png|svg)(\?[^"]*)?)"[^>]*>/i', $html, $all_matches)) {
            foreach ($all_matches[1] as $img_url) {
                $normalized = $this->normalizeUrl($img_url);
                if ($normalized) {
                    $pictograms[] = $normalized;
                }
            }
        }
    
        $feature_pictograms = $this->extractPictogramsFromFeatures($html);
        $pictograms = array_merge($pictograms, $feature_pictograms);
    
        $this->pictograms = array_unique($pictograms);
    }
    
    private function extractPictogramsFromFeatures($html) {
        $pictograms = [];
        if (preg_match('/<h3[^>]*>[^<]*Cechy produktu[^<]*<\/h3>(.*?)<(h3|div|section)/is', $html, $section_match)) {
            $specs_section = $section_match[1];
            if (preg_match_all('/<img[^>]*src="([^"]*(_picto)?\.(svg|png|jpg|jpeg))"[^>]*>/i', $specs_section, $img_matches)) {
                foreach ($img_matches[1] as $img_url) {
                    $pictograms[] = $this->normalizeUrl($img_url);
                }
            }
        }
        return $pictograms;
    }
    
    private function extractTechnicalSpecifications($html) {
        $specs = [];
        
        // Szukaj sekcji "Cechy produktu"
        if (preg_match('/<h3[^>]*>[^<]*Cechy produktu[^<]*<\/h3>(.*?)<(h3|div|section)/is', $html, $section_match)) {
            $specs_section = $section_match[1];
            
            // Szukaj listy cech
            if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $specs_section, $li_matches)) {
                foreach ($li_matches[1] as $li) {
                    $spec = $this->parseFeatureItem($li);
                    if ($spec && !$this->shouldExcludeFeature($spec['label'])) {
                        $specs[] = $spec;
                    }
                }
            }
        }
        
        // Jeśli nie znaleziono cech, szukaj w innych sekcjach
        if (empty($specs)) {
            $section_patterns = [
                '/<div[^>]*class="[^"]*product-specifications[^"]*"[^>]*>(.*?)<\/div>/is',
                '/<table[^>]*class="[^"]*data-table[^"]*"[^>]*>(.*?)<\/table>/is',
                '/<div[^>]*class="[^"]*specification[^"]*"[^>]*>(.*?)<\/div>/is'
            ];
            
            foreach ($section_patterns as $pattern) {
                if (preg_match($pattern, $html, $match)) {
                    $specs_section = $match[1];
                    if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $specs_section, $row_matches)) {
                        foreach ($row_matches[1] as $row) {
                            $spec = $this->parseTableRow($row);
                            if ($spec && !$this->shouldExcludeFeature($spec['label'])) {
                                $specs[] = $spec;
                            }
                        }
                    } elseif (preg_match_all('/<div[^>]*class="[^"]*specification-item[^"]*"[^>]*>(.*?)<\/div>/is', $specs_section, $item_matches)) {
                        foreach ($item_matches[1] as $item) {
                            $spec = $this->parseSpecificationItem($item);
                            if ($spec && !$this->shouldExcludeFeature($spec['label'])) {
                                $specs[] = $spec;
                            }
                        }
                    }
                }
            }
        }
        
        return $specs;
    }
    
    private function shouldExcludeFeature($label) {
        $label = trim($label);
        foreach ($this->excluded_features as $excluded) {
            // Sprawdzanie częściowego dopasowania (case-insensitive)
            if (stripos($label, $excluded) !== false) {
                return true;
            }
        }
        return false;
    }
    
    public function addExcludedFeature($feature) {
        if (!in_array($feature, $this->excluded_features)) {
            $this->excluded_features[] = $feature;
        }
    }
    
    public function removeExcludedFeature($feature) {
        $key = array_search($feature, $this->excluded_features);
        if ($key !== false) {
            unset($this->excluded_features[$key]);
            $this->excluded_features = array_values($this->excluded_features);
        }
    }
    
    public function getExcludedFeatures() {
        return $this->excluded_features;
    }
    
    private function parseFeatureItem($li) {
        // Parsuj element listy cech produktu
        $text = strip_tags($li, '<img>'); // Zachowaj tagi img
        $text = preg_replace('/<img[^>]*>/', '', $text); // Usuń obrazy z tekstu
        $text = trim($text);
        
        if (!empty($text)) {
            // Podziel na label i value jeśli jest dwukropek
            if (strpos($text, ':') !== false) {
                list($label, $value) = explode(':', $text, 2);
                return [
                    'label' => htmlspecialchars(trim($label)),
                    'value' => htmlspecialchars(trim($value))
                ];
            } else {
                return [
                    'label' => 'Cecha',
                    'value' => htmlspecialchars($text)
                ];
            }
        }
        return null;
    }
    
    private function parseTableRow($row) {
        if (preg_match_all('/<t(d|h)[^>]*>(.*?)<\/t(d|h)>/is', $row, $cell_matches)) {
            $cells = $cell_matches[2];
            
            if (count($cells) >= 2) {
                $label = trim(strip_tags($cells[0]));
                $value = trim(strip_tags($cells[1]));
                
                $label = preg_replace('/\s+/', ' ', $label);
                $value = preg_replace('/\s+/', ' ', $value);
                
                if (!empty($label) && !empty($value) && $label !== $value) {
                    return [
                        'label' => htmlspecialchars($label),
                        'value' => htmlspecialchars($value)
                    ];
                }
            }
        }
        return null;
    }
    
    private function parseSpecificationItem($item) {
        if (preg_match('/<span[^>]*class="[^"]*spec-name[^"]*"[^>]*>(.*?)<\/span>/is', $item, $label_match) &&
            preg_match('/<span[^>]*class="[^"]*spec-value[^"]*"[^>]*>(.*?)<\/span>/is', $item, $value_match)) {
            
            $label = trim(strip_tags($label_match[1]));
            $value = trim(strip_tags($value_match[1]));
            
            if (!empty($label) && !empty($value)) {
                return [
                    'label' => htmlspecialchars($label),
                    'value' => htmlspecialchars($value)
                ];
            }
        }
        return null;
    }
    private function generateBarcode($code) {
    return "https://barcode.tec-it.com/barcode.ashx?data=" . urlencode($code) . "&code=Code128&dpi=120&format=png&unit=px&height=35&width=200&hidehrt=TRUE";
}
    
    public function generateMultiProductHtmlTemplate($products_data) {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta name="generator" content="pdf2htmlEX"/>
    <meta id="format_conf" name="format" content="A4"/>
    <meta id="orientation" name="format" content="L"/>
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1"/>
    <style>
        @page {
            size: A4 landscape;
            margin: 0;
        }
        body {
            width: 297mm;
            height: 210mm;
            margin: 0;
            padding: 5mm;
            font-family: Arial, sans-serif;
            font-size: 10pt;
            position: relative;
            box-sizing: border-box;
        }
        .page {
            width: 100%;
            height: 100%;
            display: flex;
            flex-wrap: wrap;
            gap: 5mm;
        }
        .product-card {
            width: calc(50% - 2.5mm);
            height: calc(100% - 2mm);
            border: 1px solid #ddd;
            padding: 3mm;
            box-sizing: border-box;
            position: relative;
            page-break-inside: avoid;
        }
        .top-border {
            background-color: #da7625;
            height: 2mm;
            margin-bottom: 2mm;
        }
        .midle-border {
            background-color: #da7625;
            height: 1mm;
            margin: 2mm 0;
        }
        .table_product_data {
            margin-top: 2mm;
            border-collapse: collapse;
            width: 100%;
            font-size: 8pt;
        }
        .table_product_data td {
            border: 0.7px solid #6c6c6c;
            padding: 1mm;
        }
        .title_data {
            width: 40%;
            font-weight: bold;
			font-size: 11pt;
            background-color: #f2f2f2;
        }
        .value_data {
            width: 60%;
			font-size: 11pt;
        }
        .brand-picture {
            max-width: 20mm;
            max-height: 12mm;
            object-fit: contain;
        }
        .barcode {
            height: 6mm;
            margin-left: 2mm;
            vertical-align: middle;
        }
        .pictograms-container {
            display: flex;
            flex-wrap: wrap;
            gap: 1mm;
            margin-top: 1mm;
            padding: 1mm;
            background: #f9f9f9;
            border-radius: 1mm;
            border: 0.3mm solid #da7625;
            max-height: 22mm;
            overflow-y: auto;
        }
        .pictogram {
            width: 21mm;
            height: 21mm;
            object-fit: contain;
            padding: 0.5mm;
            background: white;
            }
        .print-info {
            position: absolute;
            bottom: 1mm;
            right: 2mm;
            font-size: 6pt;
            color: #666;
        }
        .ref-barcode {
            display: flex;
            align-items: center;
            margin: 1mm 0;
			font-size: 12pt;
        }
        .product-title {
            font-size: 16pt;
            margin: 0;
            line-height: 1.1;
            max-height: 12mm;
            overflow: hidden;
        }
        .product-image {
            max-height: 22mm;
            max-width: 55mm;
            object-fit: contain;
        }
        .section-title {
            font-size: 10pt;
            margin: 2mm 0 1mm 0;
            font-weight: bold;
        }
        .page-break {
            page-break-after: always;
            width: 100%;
            height: 0;
        }
        @media print {
            body {
                width: 297mm;
                height: 210mm;
                margin: 0;
                padding: 2mm;
				-webkit-print-color-adjust: exact; /* Chrome, Safari */
				print-color-adjust: exact;         /* Firefox */
            }
            .product-card {
                border: 0.5px solid #ccc;
            }
        }
    </style>
    <title>Karty produktów Bricoman</title>
</head>
<body>';

        $product_count = count($products_data);
        
        for ($i = 0; $i < $product_count; $i++) {
            // Rozpocznij nową stronę co 2 produkty (oprócz pierwszego produktu)
            if ($i % 2 == 0 && $i > 0) {
                $html .= '<div class="page-break"></div></div><div class="page">';
            } elseif ($i % 2 == 0) {
                $html .= '<div class="page">';
            }
            
            $data = $products_data[$i];
            $barcode_url = $this->generateBarcode($data['main_sku'][0]);
            
            $html .= '
<div class="product-card">
    <div class="top-border"></div>

    <table style="width: 100%;">
        <tr>
            <td style="width:70%; vertical-align: top;">
                <h1 class="product-title">' . htmlspecialchars($data['title'][0]) . '</h1>
            </td>
            <td style="width:30%; text-align: right; height: 25mm;">';
            
            if (!empty($data['product_picture'])) {
                $html .= '<img class="product-image" src="' . htmlspecialchars($data['product_picture']) . '" />';
            }
            
            $html .= '</td>
        </tr>
    </table>

    <table style="width: 100%; margin-top: 2mm;">
        <tr>
            <td style="width:60%; vertical-align: bottom;">
                <div class="ref-barcode">
                    <strong>Nr ref.:  ' . htmlspecialchars($data['main_sku'][0]) . '</strong>
                    <img class="barcode" src="' . $barcode_url . '" alt="" />
                </div>
            </td>
            <td style="width:40%; text-align: center; vertical-align: top;">';
            
            if (!empty($data['product_brand'])) {
                $html .= '<img class="brand-picture" src="' . htmlspecialchars($data['product_brand']) . '" />';
            }
            
            $html .= '</td>
        </tr>
    </table>

    <div class="midle-border"></div>

    <h2 class="section-title">CECHY PRODUKTU</h2>';
	
	if (!empty($data['pictograms'])) {
                $html .= '
    <div class="pictograms-container">';
                
                foreach ($data['pictograms'] as $pictogram) {
                    $html .= '
        <img class="pictogram" src="' . htmlspecialchars($pictogram) . '" alt="Piktogram" />';
                }
                
                $html .= '
    </div>';
            }

            if (!empty($data['attributes_list_object'])) {
                $html .= '
    <table class="table_product_data">
        <tr style="background-color: #6c6c6c;">
            <td style="height: 0.5mm;"></td>
            <td style="height: 0.5mm;"></td>
        </tr>';
        
                foreach ($data['attributes_list_object'] as $index => $attribute) {
                    $bg_color = ($index % 2 == 0) ? '#ffffff' : '#f2f2f2';
                    $html .= '
        <tr style="background-color: ' . $bg_color . ';">
            <td class="title_data">' . $attribute['label'] . '</td>
            <td class="value_data">' . $attribute['value'] . '</td>
        </tr>';
                }
        
                $html .= '
        <tr style="background-color: #6c6c6c;">
            <td style="height: 0.5mm;"></td>
            <td style="height: 0.5mm;"></td>
        </tr>
    </table>';
            } else {
                $html .= '
    <p style="color: #999; font-style: italic; margin: 2mm 0; font-size: 8pt;">
        Brak danych technicznych dla tego produktu.
    </p>';
            }
            
            $html .= '
    <div class="print-info">
        Data wydruku: ' . $data['print_date'] . ' r. ' . $data['print_hour'] . '
    </div>
</div>';

            // Jeśli to ostatni produkt i nieparzysta liczba, dodaj pustą kartę dla wyrównania
            if ($i == $product_count - 1 && $product_count % 2 != 0) {
                $html .= '<div class="product-card" style="border: 1px dashed #ccc; background: #f9f9f9;"></div>';
            }
        }
        
        $html .= '</div></body></html>';
        
        return $html;
    }
}

// Main processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reference_numbers_input = trim($_POST['reference_numbers'] ?? '');
    
    if (!empty($reference_numbers_input)) {
        try {
            // Podziel wprowadzone numery referencyjne (po przecinku, spacjach lub nowych liniach)
            $reference_numbers = preg_split('/[\s,\n]+/', $reference_numbers_input, -1, PREG_SPLIT_NO_EMPTY);
            $reference_numbers = array_map('trim', $reference_numbers);
            $reference_numbers = array_unique($reference_numbers);
            
            if (count($reference_numbers) > 0) {
                $scraper = new BricomanProductScraper();
                               
                $products_data = [];
                $found_products = [];
                $not_found_products = [];
                
             foreach ($reference_numbers as $reference_number) {
    if (preg_match('/^https?:\/\//i', $reference_number)) {
        // użytkownik podał pełny link
        $product_url = trim($reference_number);

        // Spróbujmy wyciągnąć numer referencyjny z URL - TYLKO OSTATNIE CYFRY
        if (preg_match_all('/(\d+)/', $product_url, $ref_matches)) {
            $all_numbers = $ref_matches[1];
            // Weź ostatnią grupę cyfr (najprawdopodobniej numer referencyjny)
            $reference_number = end($all_numbers);
        }
    } else {
        // standardowe szukanie po numerze w sitemap
        $product_url = $scraper->findProductByReference($reference_number);
    }

    if ($product_url) {
        $product_data = $scraper->getProductData($product_url, $reference_number);
        
        if (!isset($product_data['error'])) {
            $products_data[] = $product_data;
            $found_products[] = [
                'reference' => $reference_number,
                'url' => $product_url,
                'title' => $product_data['title'][0]
            ];
        } else {
            $not_found_products[] = [
                'reference' => $reference_number,
                'error' => $product_data['error']
            ];
        }
    } else {
        $not_found_products[] = [
            'reference' => $reference_number,
            'error' => 'Nie znaleziono produktu'
        ];
    }

    usleep(500000); // 0.5s przerwy
}
                
                if (!empty($products_data)) {
                    $html_output = $scraper->generateMultiProductHtmlTemplate($products_data);
                    $filename = "products_" . date('Y-m-d_H-i-s') . ".html";
                    
                    if (file_put_contents($filename, $html_output)) {
                        $result = [
                            'success' => true,
                            'filename' => $filename,
                            'found_count' => count($found_products),
                            'not_found_count' => count($not_found_products),
                            'found_products' => $found_products,
                            'not_found_products' => $not_found_products,
                            'excluded_features' => $scraper->getExcludedFeatures()
                        ];
                    } else {
                        $result = ['error' => 'Nie udało się zapisać pliku'];
                    }
                } else {
                    $result = ['error' => 'Nie znaleziono żadnych produktów'];
                }
            } else {
                $result = ['error' => 'Nie podano żadnych numerów referencyjnych'];
            }
        } catch (Exception $e) {
            $result = ['error' => "Błąd: " . $e->getMessage()];
        }
    } else {
        $result = ['error' => 'Proszę podać numery referencyjne'];
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>MoKaTe - Moje Karty Techniczne</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            background: #fff; 
            margin: 20px;
            color: #333;
        }
        
        .container { 
            max-width: 800px; 
            margin: 0 auto; 
        }
        
        .header { 
            background: #e67e22; 
            color: white; 
            padding: 15px; 
            text-align: center;
            margin-bottom: 20px;
        }
        
        .instructions { 
            background: #f9f9f9; 
            padding: 15px; 
            margin-bottom: 20px; 
            border-left: 3px solid #e67e22;
        }
        
        .search-form { 
            margin-bottom: 20px; 
        }
        
        textarea { 
            width: 100%; 
            height: 120px; 
            padding: 10px; 
            border: 1px solid #ccc; 
            margin-bottom: 10px;
        }
        
        .btn-primary {
            padding: 10px 20px; 
            background: #e67e22; 
            color: white; 
            border: none; 
            cursor: pointer; 
        }
        
        .btn-primary:hover { 
            background: #d35400; 
        }
        
        .alert {
            padding: 10px;
            margin: 10px 0;
            border-left: 3px solid;
        }
        
        .alert-error { 
            background: #ffeaea; 
            border-left-color: #c0392b;
        }
        
        .alert-success { 
            background: #f0fff0; 
            border-left-color: #27ae60;
        }
        
        .download-section { 
            text-align: center;  
            padding: 15px; 
            background: #f0f8f0; 
            margin: 15px 0;
        }
        
        .download-link { 
            display: inline-block;
            padding: 10px 15px; 
            background: #27ae60; 
            color: white; 
            text-decoration: none; 
        }
        
        .products-list { 
            margin: 10px 0; 
        }
        
        .product-item { 
            padding: 8px 0; 
            border-bottom: 1px solid #eee; 
        }
        
        .excluded-features { 
            background: #f9f9f9; 
            padding: 10px; 
            margin: 15px 0; 
            font-size: 12px; 
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>MoKaTe - Moje Karty Techniczne</h1>
        </div>
        
        <div class="instructions">
            <strong>Instrukcja:</strong> Wpisz numery referencyjne produktów (jeden pod drugim lub oddzielone przecinkami/spacjami).
        </div>
        
        <div class="search-form">
            <form method="POST">
                <textarea name="reference_numbers" placeholder="Numery referencyjne, np.:&#10;123456&#10;789012&#10;345678" required><?= htmlspecialchars($_POST['reference_numbers'] ?? '') ?></textarea>
                <button type="submit" class="btn-primary">Generuj karty techniczne</button>
            </form>
        </div>

        <?php if (isset($result)): ?>
            <?php if (isset($result['error'])): ?>
                <div class="alert alert-error">
                    <strong>Błąd:</strong> <?= htmlspecialchars($result['error']) ?>
                </div>
            <?php else: ?>
                <div class="alert alert-success">
                    <strong>Sukces!</strong> Znaleziono: <?= $result['found_count'] ?> 
                    <?php if ($result['not_found_count'] > 0): ?>
                        | Nie znaleziono: <?= $result['not_found_count'] ?>
                    <?php endif; ?>
                    
                    <div class="download-section">
                        <a href="<?= htmlspecialchars($result['filename']) ?>" download class="download-link">
                            Pobierz plik HTML
                        </a>
                    </div>
                    
                    <?php if (!empty($result['found_products'])): ?>
                        <div class="products-list">
                            <strong>Znalezione produkty:</strong>
                            <?php foreach ($result['found_products'] as $product): ?>
                                <div class="product-item">✅ <?= htmlspecialchars($product['reference']) ?> - <?= htmlspecialchars($product['title']) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="excluded-features">
            <strong>Wykluczone cechy:</strong> 
            <?php
            $scraper_temp = new BricomanProductScraper();
            $excluded = $scraper_temp->getExcludedFeatures();
            echo implode(', ', $excluded);
            ?>
        </div>
    </div>
</body>
</html>
