<?php
/**
 * MediSeba - Simple PDF Document Builder
 *
 * Lightweight PDF generator using built-in Type1 fonts.
 * Suitable for simple receipts and prescriptions without external dependencies.
 */

declare(strict_types=1);

namespace MediSeba\Utils;

class SimplePdfDocument
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;
    private const MARGIN_LEFT = 48.0;
    private const MARGIN_RIGHT = 48.0;
    private const MARGIN_TOP = 54.0;
    private const MARGIN_BOTTOM = 54.0;

    private array $pages = [];
    private int $currentPage = -1;
    private float $cursorY = self::MARGIN_TOP;

    public function __construct(private string $title = 'Document')
    {
        $this->addPage();
    }

    public function addTitle(string $text): void
    {
        $this->addWrappedText($text, 22, 'F2', 30);
        $this->addDivider();
    }

    public function addSection(string $text): void
    {
        $this->ensureVerticalSpace(28);
        $this->cursorY += 6;
        $this->addWrappedText($text, 15, 'F2', 22);
    }

    public function addMetaLine(string $label, string $value): void
    {
        $this->addWrappedText(sprintf('%s: %s', $label, $value), 11, 'F1', 18);
    }

    public function addParagraph(string $text, float $fontSize = 11, string $font = 'F1'): void
    {
        $this->addWrappedText($text, $fontSize, $font, $fontSize * 1.6);
    }

    public function addBulletList(array $items, string $emptyFallback = 'No items listed.'): void
    {
        if (!$items) {
            $this->addParagraph($emptyFallback);
            return;
        }

        foreach ($items as $item) {
            $this->addWrappedText('- ' . $item, 11, 'F1', 17);
        }
    }

    public function addDivider(): void
    {
        $this->ensureVerticalSpace(16);
        $y = $this->pdfY($this->cursorY);
        $command = sprintf(
            "0.6 w %.2F %.2F m %.2F %.2F l S\n",
            self::MARGIN_LEFT,
            $y,
            self::PAGE_WIDTH - self::MARGIN_RIGHT,
            $y
        );
        $this->pages[$this->currentPage] .= $command;
        $this->cursorY += 12;
    }

    public function addSpacer(float $height = 10): void
    {
        $this->ensureVerticalSpace($height);
        $this->cursorY += $height;
    }

    public function output(): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        $pageReferences = [];
        $objectIndex = 5;

        foreach ($this->pages as $pageContent) {
            $pageObject = $objectIndex++;
            $contentObject = $objectIndex++;

            $stream = "q\n" . $pageContent . "Q\n";
            $objects[$contentObject] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($stream),
                $stream
            );

            $objects[$pageObject] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentObject
            );

            $pageReferences[] = sprintf('%d 0 R', $pageObject);
        }

        $objects[2] = sprintf(
            "<< /Type /Pages /Kids [%s] /Count %d >>",
            implode(' ', $pageReferences),
            count($pageReferences)
        );

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $number => $objectContent) {
            $offsets[$number] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $number, $objectContent);
        }

        $xrefOffset = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n", count($objects) + 1);
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n";
        $pdf .= sprintf(
            "<< /Size %d /Root 1 0 R /Info << /Title (%s) >> >>\n",
            count($objects) + 1,
            $this->escapePdfString($this->normalizeText($this->title))
        );
        $pdf .= "startxref\n";
        $pdf .= $xrefOffset . "\n";
        $pdf .= "%%EOF";

        return $pdf;
    }

    private function addPage(): void
    {
        $this->pages[] = '';
        $this->currentPage = count($this->pages) - 1;
        $this->cursorY = self::MARGIN_TOP;
    }

    private function addWrappedText(string $text, float $fontSize, string $font, float $lineHeight): void
    {
        $lines = $this->wrapText($text, $fontSize);

        if (!$lines) {
            $this->ensureVerticalSpace($lineHeight);
            $this->cursorY += $lineHeight;
            return;
        }

        foreach ($lines as $line) {
            $this->ensureVerticalSpace($lineHeight);
            $this->writeTextLine($line, $fontSize, $font);
            $this->cursorY += $lineHeight;
        }
    }

    private function writeTextLine(string $text, float $fontSize, string $font): void
    {
        $command = sprintf(
            "BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $font,
            $fontSize,
            self::MARGIN_LEFT,
            $this->pdfY($this->cursorY),
            $this->escapePdfString($text)
        );

        $this->pages[$this->currentPage] .= $command;
    }

    private function ensureVerticalSpace(float $requiredHeight): void
    {
        if ($this->cursorY + $requiredHeight <= self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
            return;
        }

        $this->addPage();
    }

    private function wrapText(string $text, float $fontSize): array
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return [];
        }

        $maxWidth = self::PAGE_WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;
        $approxCharWidth = max(4.8, $fontSize * 0.53);
        $maxChars = max(12, (int) floor($maxWidth / $approxCharWidth));
        $paragraphs = preg_split("/\r\n|\r|\n/", $normalized) ?: [];
        $lines = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);

            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }

            $words = preg_split('/\s+/', $paragraph) ?: [];
            $currentLine = '';

            foreach ($words as $word) {
                $word = trim($word);
                if ($word === '') {
                    continue;
                }

                if (strlen($word) > $maxChars) {
                    if ($currentLine !== '') {
                        $lines[] = $currentLine;
                        $currentLine = '';
                    }

                    foreach (str_split($word, $maxChars) as $chunk) {
                        $lines[] = $chunk;
                    }
                    continue;
                }

                $candidate = $currentLine === '' ? $word : $currentLine . ' ' . $word;

                if (strlen($candidate) <= $maxChars) {
                    $currentLine = $candidate;
                    continue;
                }

                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }
                $currentLine = $word;
            }

            if ($currentLine !== '') {
                $lines[] = $currentLine;
            }
        }

        return $lines;
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\t", "\v"], ' ', $text);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }

        $text = preg_replace('/[^\x0A\x0D\x20-\x7E]/', ' ', $text) ?? $text;
        $text = preg_replace('/[ ]{2,}/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function escapePdfString(string $text): string
    {
        return strtr($text, [
            '\\' => '\\\\',
            '(' => '\\(',
            ')' => '\\)',
        ]);
    }

    private function pdfY(float $topBasedY): float
    {
        return self::PAGE_HEIGHT - $topBasedY;
    }
}
