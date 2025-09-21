<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

class BricomanProductScraper {
    private $base_url = "https://www.bricoman.pl";
    private $sitemap_urls = [
        "https://www.bricoman.pl/media/sitemap/products-1-1.xml",
        "https://www.bricoman.pl/media/sitemap/products-1-2.xml"
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
    
    public function getProductData($product_url) {
        try {
            $html = $this->makeRequest($product_url);
            if (!$html) {
                return ["error" => "Nie udało się pobrać strony produktu"];
            }
            
            return $this->parseProductPage($html, $product_url);
            
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
                'timeout' => 10,
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
    
    private function parseProductPage($html, $product_url) {
        // Wydziel kod referencji z URL - ostatnie cyfry w adresie
        $reference_code = $this->extractReferenceCode($product_url);
        
        $data = [
            'title' => ['Produkt Bricoman'],
            'main_sku' => [$reference_code],
            'product_picture' => null,
            'product_brand' => null,
            'attributes_list_object' => $this->extractTechnicalSpecifications($html),
            'print_date' => date('d.m.Y'),
            'print_hour' => date('H:i'),
            'pictos' => []
        ];
        
        // Extract title
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $html, $match)) {
            $data['title'][0] = strip_tags(trim($match[1]));
        }
        
        // Extract product image
        if (preg_match('/<img[^>]*src="([^"]*\.(jpg|jpeg|png|gif))"[^>]*/i', $html, $match)) {
            $data['product_picture'] = $match[1];
        }
        
        return $data;
    }
    
    private function extractReferenceCode($product_url) {
        // Wydziel ostatnie cyfry z URL (kod referencji)
        $path = parse_url($product_url, PHP_URL_PATH);
        $parts = explode('/', $path);
        $last_part = end($parts);
        
        // Szukaj ciągu cyfr na końcu URL
        if (preg_match('/(\d+)$/', $last_part, $match)) {
            return $match[1];
        }
        
        // Jeśli nie znaleziono cyfr, zwróć ostatnią część URL
        return $last_part;
    }
    
    private function extractTechnicalSpecifications($html) {
        $specs = [];
        
        // Szukaj różnych wariantów sekcji ze specyfikacjami
        $section_patterns = [
            '/<div[^>]*class="[^"]*specification[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<div[^>]*class="[^"]*technical[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<table[^>]*class="[^"]*spec[^"]*"[^>]*>(.*?)<\/table>/is',
            '/<div[^>]*id="[^"]*spec[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<div[^>]*data-testid="[^"]*specification[^"]*"[^>]*>(.*?)<\/div>/is'
        ];
        
        $specs_section = '';
        foreach ($section_patterns as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                $specs_section = $match[1];
                break;
            }
        }
        
        // Jeśli znaleziono sekcję, parsuj specyfikacje
        if (!empty($specs_section)) {
            // Metoda 1: Parsowanie tabeli
            if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $specs_section, $row_matches)) {
                foreach ($row_matches[1] as $row) {
                    $spec = $this->parseSpecificationRow($row);
                    if ($spec) {
                        $specs[] = $spec;
                    }
                }
            }
            
            // Metoda 2: Parsowanie listy (divy)
            if (empty($specs) && preg_match_all('/<div[^>]*class="[^"]*spec-item[^"]*"[^>]*>(.*?)<\/div>/is', $specs_section, $item_matches)) {
                foreach ($item_matches[1] as $item) {
                    $spec = $this->parseSpecificationItem($item);
                    if ($spec) {
                        $specs[] = $spec;
                    }
                }
            }
            
            // Metoda 3: Parsowanie dl list
            if (empty($specs) && preg_match_all('/<dl[^>]*>(.*?)<\/dl>/is', $specs_section, $dl_matches)) {
                foreach ($dl_matches[1] as $dl) {
                    if (preg_match_all('/<dt[^>]*>(.*?)<\/dt>\s*<dd[^>]*>(.*?)<\/dd>/is', $dl, $spec_matches, PREG_SET_ORDER)) {
                        foreach ($spec_matches as $match) {
                            $label = trim(strip_tags($match[1]));
                            $value = trim(strip_tags($match[2]));
                            if (!empty($label) && !empty($value)) {
                                $specs[] = ['label' => $label, 'value' => $value];
                            }
                        }
                    }
                }
            }
        }
        
        // Jeśli nie znaleziono specyfikacji, szukaj bezpośrednio w HTML
        if (empty($specs)) {
            // Szukaj wszystkich tabel
            if (preg_match_all('/<tr[^>]*>.*?<td[^>]*>(.*?)<\/td>.*?<td[^>]*>(.*?)<\/td>.*?<\/tr>/is', $html, $table_matches, PREG_SET_ORDER)) {
                foreach ($table_matches as $match) {
                    $label = trim(strip_tags($match[1]));
                    $value = trim(strip_tags($match[2]));
                    if (!empty($label) && !empty($value) && strlen($label) < 50 && strlen($value) < 100) {
                        $specs[] = ['label' => $label, 'value' => $value];
                    }
                }
            }
        }
        
        return array_slice($specs, 0, 15); // Ogranicz do 15 specyfikacji
    }
    
    private function parseSpecificationRow($row) {
        if (preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $row, $cell_matches)) {
            if (count($cell_matches[1]) >= 2) {
                $label = trim(strip_tags($cell_matches[1][0]));
                $value = trim(strip_tags($cell_matches[1][1]));
                
                if (!empty($label) && !empty($value)) {
                    return ['label' => $label, 'value' => $value];
                }
            }
        }
        return null;
    }
    
    private function parseSpecificationItem($item) {
        if (preg_match('/<span[^>]*class="[^"]*label[^"]*"[^>]*>(.*?)<\/span>/is', $item, $label_match) &&
            preg_match('/<span[^>]*class="[^"]*value[^"]*"[^>]*>(.*?)<\/span>/is', $item, $value_match)) {
            $label = trim(strip_tags($label_match[1]));
            $value = trim(strip_tags($value_match[1]));
            if (!empty($label) && !empty($value)) {
                return ['label' => $label, 'value' => $value];
            }
        }
        return null;
    }
    
    public function generateHtmlTemplate($data) {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta name="generator" content="pdf2htmlEX"/>
    <meta id="format_conf" name="format" content="A5"/>
    <meta id="orientation" name="format" content="P"/>
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1"/>
    <style>
        body{ width:100%; height:3508px; position:relative; page-break-inside:avoid; font-family: Arial, sans-serif; }
        .top-border{ background-color: #da7625; height:10px; margin-bottom:10px; }
        .midle-border{ background-color: #da7625; height:5px; }
        .table_product_data { margin-top:5px; border-collapse: collapse; width: 100%; max-width: 550px; }
        .table_product_data td{ border: 1px solid #dddddd; padding: 8px; }
        .title_data{ width:160px; font-weight: bold; background-color: #f2f2f2; }
        .value_data{ width:285px; }
        .brand-picture{ max-width:100px; max-height: 60px; }
    </style>
    <title>' . htmlspecialchars($data['title'][0]) . '</title>
</head>
<body>
<div class="top-border"></div>

<table style="width: 100%;">
    <tr>
        <td style="width:70%; vertical-align: top;">
            <h1 style="font-size:27px; margin: 0;">' . htmlspecialchars($data['title'][0]) . '</h1>
        </td>
        <td style="width:30%; text-align: right;">';
        
        if (!empty($data['product_picture'])) {
            $html .= '<img style="max-height: 130px; max-width: 130px;" src="' . htmlspecialchars($data['product_picture']) . '" />';
        }
        
        $html .= '</td>
    </tr>
</table>

<table style="width: 100%; margin-top: 20px;">
    <tr>
        <td>Nr ref. ' . htmlspecialchars($data['main_sku'][0]) . '</td>
        <td style="text-align: center;">';
        
        if (!empty($data['product_brand'])) {
            $html .= '<img class="brand-picture" src="' . htmlspecialchars($data['product_brand']) . '" />';
        }
        
        $html .= '</td>
    </tr>
</table>

<div class="midle-border"></div>

<h2 style="margin: 20px 0 10px 0;">CECHY PRODUKTU</h2>

<table class="table_product_data">
    <tr style="background-color: #6c6c6c;">
        <td style="height: 5px;"></td>
        <td style="height: 5px;"></td>
    </tr>';
    
    foreach ($data['attributes_list_object'] as $index => $attribute) {
        $bg_color = ($index % 2 == 0) ? '#ffffff' : '#f2f2f2';
        $html .= '
    <tr style="background-color: ' . $bg_color . ';">
        <td class="title_data">' . htmlspecialchars($attribute['label']) . '</td>
        <td class="value_data">' . htmlspecialchars($attribute['value']) . '</td>
    </tr>';
    }
    
    $html .= '
    <tr style="background-color: #6c6c6c;">
        <td style="height: 5px;"></td>
        <td style="height: 5px;"></td>
    </tr>
</table>

<div style="margin-top: 30px; font-size: 8pt;">
    Data wydruku: ' . $data['print_date'] . ' r. ' . $data['print_hour'] . '
</div>

</body>
</html>';
        
        return $html;
    }
}

// Main processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reference_number = trim($_POST['reference_number'] ?? '');
    
    if (!empty($reference_number)) {
        try {
            $scraper = new BricomanProductScraper();
            $product_url = $scraper->findProductByReference($reference_number);
            
            if ($product_url) {
                $product_data = $scraper->getProductData($product_url);
                
                if (!isset($product_data['error'])) {
                    $html_output = $scraper->generateHtmlTemplate($product_data);
                    $filename = "product_" . preg_replace('/[^a-zA-Z0-9]/', '_', $reference_number) . ".html";
                    
                    if (file_put_contents($filename, $html_output)) {
                        $result = [
                            'success' => true,
                            'product_url' => $product_url,
                            'filename' => $filename
                        ];
                    } else {
                        $result = ['error' => 'Nie udało się zapisać pliku'];
                    }
                } else {
                    $result = ['error' => $product_data['error']];
                }
            } else {
                $result = ['error' => "Nie znaleziono produktu o numerze: $reference_number"];
            }
        } catch (Exception $e) {
            $result = ['error' => "Błąd: " . $e->getMessage()];
        }
    } else {
        $result = ['error' => 'Proszę podać numer referencyjny'];
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Wyszukiwarka produktów Bricoman</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .search-form { background: #e8f4fc; padding: 20px; border-radius: 8px; margin-bottom: 20px; text-align: center; }
        input[type="text"] { padding: 12px; width: 250px; border: 2px solid #3498db; border-radius: 6px; font-size: 16px; margin-right: 10px; }
        button { padding: 12px 24px; background: #3498db; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; }
        button:hover { background: #2980b9; }
        .error { background: #ffebee; color: #c62828; padding: 15px; border-radius: 6px; margin: 10px 0; }
        .success { background: #e8f5e8; color: #2e7d32; padding: 15px; border-radius: 6px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="text-align: center; color: #2c3e50;">🔍 Wyszukiwarka produktów Bricoman</h1>
        
        <div class="search-form">
            <form method="POST">
                <input type="text" name="reference_number" 
                       placeholder="Wpisz numer referencyjny" 
                       value="<?= htmlspecialchars($_POST['reference_number'] ?? '') ?>" required>
                <button type="submit">Szukaj produktu</button>
            </form>
        </div>

        <?php if (isset($result)): ?>
            <?php if (isset($result['error'])): ?>
                <div class="error">
                    <h3>❌ Błąd</h3>
                    <p><?= htmlspecialchars($result['error']) ?></p>
                </div>
            <?php else: ?>
                <div class="success">
                    <h3>✅ Znaleziono produkt!</h3>
                    <p><strong>URL produktu:</strong> <a href="<?= htmlspecialchars($result['product_url']) ?>" target="_blank"><?= htmlspecialchars($result['product_url']) ?></a></p>
                    <p><a href="<?= htmlspecialchars($result['filename']) ?>" download style="color: #2e7d32; text-decoration: none; font-weight: bold;">
                        📥 Pobierz kartę produktu
                    </a></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>