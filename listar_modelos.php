<?php
// ============================================================
//  LISTAR MODELOS DISPONÍVEIS NA TUA CONTA GEMINI
// ============================================================

require_once 'configuracao.php';

// Endpoint para listar os modelos (método GET)
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . GEMINI_CHAVE_API;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false, // Evita problemas de SSL no XAMPP
    CURLOPT_SSL_VERIFYHOST => false
]);

$resposta = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
    $dados = json_decode($resposta, true);
    echo "<h1>Modelos Disponíveis para a tua Chave:</h1><ul>";
    
    foreach ($dados['models'] as $modelo) {
        // Mostra apenas os modelos que suportam geração de texto
        if (in_array('generateContent', $modelo['supportedGenerationMethods'])) {
            // O nome do modelo vem no formato "models/nome-do-modelo"
            $nome_limpo = str_replace('models/', '', $modelo['name']);
            echo "<li><strong>{$nome_limpo}</strong> — {$modelo['description']}</li>";
        }
    }
    echo "</ul>";
} else {
    echo "Erro ao listar modelos. Código HTTP: " . $http_code . "<br>";
    echo "<pre>" . htmlspecialchars($resposta) . "</pre>";
}
?>