<?php
/**
 * MediSeba - Secure Gemini AI Service
 * 
 * Safely interacts with Google Gemini REST API.
 * Keeps API keys encapsulated entirely server-side.
 */

declare(strict_types=1);

namespace MediSeba\Utils;

use MediSeba\Config\Environment;

class GeminiService
{
    private string $apiKey;
    private string $modelVersion;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct()
    {
        $this->apiKey = Environment::get('GEMINI_API_KEY', '');
        $this->modelVersion = Environment::get('GEMINI_MODEL_VERSION', 'gemini-1.5-pro');
    }

    /**
     * Send a prompt to the Gemini API and securely retrieve text
     */
    public function generateText(string $prompt, string $systemInstruction = ''): array
    {
        if (empty($this->apiKey) || $this->apiKey === 'your_secure_gemini_key_here') {
            return [
                'success' => false,
                'data' => "AI integration is not fully configured on the server. Please notify system administration."
            ];
        }

        $endpoint = "{$this->baseUrl}{$this->modelVersion}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 8192,
            ]
        ];

        if (!empty($systemInstruction)) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ];
        }

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Strict SSL validation
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30s timeout

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Fallback System Implementation
        if ($response === false || $httpCode !== 200) {
            error_log("Gemini Connection Failed: " . $curlError . " HTTP: " . $httpCode);
            // Graceful degradation message sent back to UI
            return [
                'success' => false,
                'data' => 'AI service is temporarily unavailable. Please try again later.'
            ];
        }

        $responseData = json_decode($response, true);
        
        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'success' => true,
                'data' => trim($responseData['candidates'][0]['content']['parts'][0]['text'])
            ];
        }

        return [
            'success' => false,
            'data' => 'AI service is temporarily unavailable. Please try again later.'
        ];
    }
}
