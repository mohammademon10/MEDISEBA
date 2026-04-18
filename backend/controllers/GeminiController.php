<?php
/**
 * MediSeba - Gemini AI Controller
 * 
 * Provides HTTP endpoints for Patient and Doctor interactions 
 * with the Gemini LLM engine.
 */

declare(strict_types=1);

namespace MediSeba\Controllers;

use MediSeba\Utils\Response;
use MediSeba\Utils\Validator;
use MediSeba\Utils\GeminiService;

class GeminiController
{
    private GeminiService $gemini;

    public function __construct()
    {
        $this->gemini = new GeminiService();
    }

    /**
     * Patient Output: Explain medical terms simply
     * POST /api/gemini/explain
     */
    public function explainTerm(array $user, array $request): void
    {
        $validator = Validator::quick($request, [
            'term' => 'required|min:2|max:500'
        ]);

        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }

        $term = htmlspecialchars($request['term']);
        
        $systemInstruction = "You are a helpful, empathetic medical assistant. Explain the requested medical concept so that a non-medical patient can easily understand it. Use simple analogies and do not provide direct medical advice.";
        $prompt = "Please explain this medical term or diagnosis clearly: " . $term;

        $result = $this->gemini->generateText($prompt, $systemInstruction);

        if ($result['success']) {
            Response::success('Explanation generated', ['response' => $result['data']]);
        } else {
            Response::error($result['data'], [], 503);
        }
    }

    /**
     * Patient Output: Summarize symptoms into medical structure
     * POST /api/gemini/summarize-symptoms
     */
    public function parseSymptoms(array $user, array $request): void
    {
        $validator = Validator::quick($request, [
            'raw_text' => 'required|max:2000'
        ]);

        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }

        $rawText = htmlspecialchars($request['raw_text']);
        
        $systemInstruction = "You are a patient intake specialist. Read the patient's conversational description of how they feel and convert it into a structured, concise symptom list for a doctor to review quickly. Do not make a diagnosis.";
        $prompt = "Patient says: " . $rawText . "\n\nConvert this to a concise clinical bulleted list of symptoms.";

        $result = $this->gemini->generateText($prompt, $systemInstruction);

        if ($result['success']) {
            Response::success('Symptoms summarized', ['response' => $result['data']]);
        } else {
            Response::error($result['data'], [], 503);
        }
    }

    /**
     * Doctor Output: Generate clinical case summary
     * POST /api/gemini/clinical-summary
     */
    public function clinicalSummary(array $user, array $request): void
    {
        $validator = Validator::quick($request, [
            'symptoms' => 'required|max:2000',
            'notes' => 'max:2000'
        ]);

        if (!$validator['valid']) {
            Response::validationError($validator['errors']);
        }

        $symptoms = htmlspecialchars($request['symptoms']);
        $notes = empty($request['notes']) ? 'None' : htmlspecialchars($request['notes']);
        
        $systemInstruction = "You are an AI diagnostic assistant for physicians. Analyze the symptoms and physician notes provided to output a highly professional diagnostic-style summary. Warn that it is non-final medical advice.";
        $prompt = "Symptoms: {$symptoms}\nNotes: {$notes}\n\nProvide a possible clinical summary and recommendations for the practicing physician.";

        $result = $this->gemini->generateText($prompt, $systemInstruction);

        if ($result['success']) {
            Response::success('Diagnostic summary generated', ['response' => $result['data']]);
        } else {
            Response::error($result['data'], [], 503);
        }
    }
}
