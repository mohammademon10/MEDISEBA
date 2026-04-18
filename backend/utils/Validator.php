<?php
/**
 * MediSeba - Input Validation Utility
 * 
 * Comprehensive validation rules for all input types
 */

declare(strict_types=1);

namespace MediSeba\Utils;

class Validator
{
    private array $data;
    private array $errors = [];
    private array $rules = [];
    
    public function __construct(array $data)
    {
        $this->data = $data;
    }
    
    /**
     * Define validation rules
     * 
     * @param array $rules Format: ['field' => 'rule1|rule2:param|rule3']
     */
    public function rules(array $rules): self
    {
        $this->rules = $rules;
        return $this;
    }
    
    /**
     * Run validation
     */
    public function validate(): bool
    {
        $this->errors = [];
        
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            
            foreach ($rules as $rule) {
                $this->applyRule($field, $value, $rule);
            }
        }
        
        return empty($this->errors);
    }
    
    /**
     * Apply single validation rule
     */
    private function applyRule(string $field, $value, string $rule): void
    {
        // Parse rule with parameters
        $params = [];
        if (strpos($rule, ':') !== false) {
            list($ruleName, $paramString) = explode(':', $rule, 2);
            $params = explode(',', $paramString);
        } else {
            $ruleName = $rule;
        }
        
        $method = 'validate' . ucfirst($ruleName);
        
        if (method_exists($this, $method)) {
            $result = $this->$method($field, $value, $params);
            if ($result !== true) {
                $this->errors[$field][] = $result;
            }
        }
    }
    
    /**
     * Get validation errors
     */
    public function errors(): array
    {
        return $this->errors;
    }
    
    /**
     * Get first error message
     */
    public function firstError(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            return $fieldErrors[0] ?? null;
        }
        return null;
    }
    
    // ==================== VALIDATION RULES ====================
    
    /**
     * Required field
     */
    private function validateRequired(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return "The {$field} field is required.";
        }
        return true;
    }
    
    /**
     * Minimum length
     */
    private function validateMin(string $field, $value, array $params): bool|string
    {
        if ($value === null) return true;
        
        $min = (float) ($params[0] ?? 0);
        
        // For numeric values, compare as numbers
        if (is_numeric($value)) {
            if ((float) $value < $min) {
                return "The {$field} must be at least {$min}.";
            }
            return true;
        }
        
        // For strings/arrays, compare length/count
        $length = is_string($value) ? strlen($value) : (is_array($value) ? count($value) : 0);
        
        if ($length < (int) $min) {
            return "The {$field} must be at least {$min} characters.";
        }
        return true;
    }
    
    /**
     * Maximum length
     */
    private function validateMax(string $field, $value, array $params): bool|string
    {
        if ($value === null) return true;
        
        $max = (float) ($params[0] ?? PHP_INT_MAX);
        
        // For numeric values, compare as numbers
        if (is_numeric($value)) {
            if ((float) $value > $max) {
                return "The {$field} must not exceed {$max}.";
            }
            return true;
        }
        
        // For strings/arrays, compare length/count
        $length = is_string($value) ? strlen($value) : (is_array($value) ? count($value) : 0);
        
        if ($length > (int) $max) {
            return "The {$field} must not exceed {$max} characters.";
        }
        return true;
    }
    
    /**
     * Exact length
     */
    private function validateLength(string $field, $value, array $params): bool|string
    {
        if ($value === null) return true;
        
        $length = (int) ($params[0] ?? 0);
        if (strlen((string) $value) !== $length) {
            return "The {$field} must be exactly {$length} characters.";
        }
        return true;
    }
    
    /**
     * Email validation
     */
    private function validateEmail(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "The {$field} must be a valid email address.";
        }
        return true;
    }
    
    /**
     * Phone validation (Bangladesh format)
     */
    private function validatePhone(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        if (!Security::validatePhone($value)) {
            return "The {$field} must be a valid phone number.";
        }
        return true;
    }
    
    /**
     * Numeric validation
     */
    private function validateNumeric(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        if (!is_numeric($value)) {
            return "The {$field} must be a number.";
        }
        return true;
    }
    
    /**
     * Integer validation
     */
    private function validateInteger(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        if (!filter_var($value, FILTER_VALIDATE_INT)) {
            return "The {$field} must be an integer.";
        }
        return true;
    }
    
    /**
     * Minimum value
     */
    private function validateMinValue(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        $min = (float) ($params[0] ?? 0);
        if ((float) $value < $min) {
            return "The {$field} must be at least {$min}.";
        }
        return true;
    }
    
    /**
     * Maximum value
     */
    private function validateMaxValue(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        $max = (float) ($params[0] ?? PHP_FLOAT_MAX);
        if ((float) $value > $max) {
            return "The {$field} must not exceed {$max}.";
        }
        return true;
    }
    
    /**
     * Date validation
     */
    private function validateDate(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        $format = $params[0] ?? 'Y-m-d';
        $date = \DateTime::createFromFormat($format, $value);
        
        if (!$date || $date->format($format) !== $value) {
            return "The {$field} must be a valid date.";
        }
        return true;
    }
    
    /**
     * Future date validation
     */
    private function validateFuture(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        $date = strtotime($value);
        if ($date === false || $date < strtotime('today')) {
            return "The {$field} must be a future or current date.";
        }
        return true;
    }
    
    /**
     * Past date validation
     */
    private function validatePast(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        $date = strtotime($value);
        if ($date === false || $date >= time()) {
            return "The {$field} must be a past date.";
        }
        return true;
    }
    
    /**
     * In array validation
     */
    private function validateIn(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        if (!in_array($value, $params, true)) {
            $allowed = implode(', ', $params);
            return "The {$field} must be one of: {$allowed}.";
        }
        return true;
    }
    
    /**
     * JSON validation
     */
    private function validateJson(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        if (is_string($value)) {
            json_decode($value);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return "The {$field} must be valid JSON.";
            }
        } elseif (!is_array($value)) {
            return "The {$field} must be valid JSON.";
        }
        return true;
    }
    
    /**
     * Array validation
     */
    private function validateArray(string $field, $value, array $params): bool|string
    {
        if ($value === null) return true;
        
        if (!is_array($value)) {
            return "The {$field} must be an array.";
        }
        return true;
    }
    
    /**
     * Confirm password validation
     */
    private function validateConfirmed(string $field, $value, array $params): bool|string
    {
        $confirmField = $field . '_confirmation';
        $confirmValue = $this->data[$confirmField] ?? null;
        
        if ($value !== $confirmValue) {
            return "The {$field} confirmation does not match.";
        }
        return true;
    }
    
    /**
     * Regex pattern validation
     */
    private function validateRegex(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        $pattern = $params[0] ?? '//';
        if (!preg_match($pattern, $value)) {
            return "The {$field} format is invalid.";
        }
        return true;
    }
    
    /**
     * URL validation
     */
    private function validateUrl(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return "The {$field} must be a valid URL.";
        }
        return true;
    }
    
    /**
     * UUID validation
     */
    private function validateUuid(string $field, $value, array $params): bool|string
    {
        if ($value === null || $value === '') return true;
        
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        if (!preg_match($pattern, $value)) {
            return "The {$field} must be a valid UUID.";
        }
        return true;
    }
    
    // ==================== STATIC VALIDATORS ====================
    
    /**
     * Quick validate method
     */
    public static function quick(array $data, array $rules): array
    {
        $validator = new self($data);
        $validator->rules($rules);
        
        if ($validator->validate()) {
            return ['valid' => true, 'errors' => []];
        }
        
        return ['valid' => false, 'errors' => $validator->errors()];
    }
    
    /**
     * Validate Bangladeshi phone number
     */
    public static function phone(string $phone): bool
    {
        return Security::validatePhone($phone);
    }
    
    /**
     * Validate email
     */
    public static function email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate date format
     */
    public static function date(string $date, string $format = 'Y-m-d'): bool
    {
        $d = \DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}
