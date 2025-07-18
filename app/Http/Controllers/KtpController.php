<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;
use thiagoalessio\TesseractOCR\TesseractOCR;

class KtpController extends Controller
{
    /**
     * Scan KTP image and extract information.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function scan(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:4096',
        ]);

        $uploadedFile = $request->file('image');
        $tempPath = null;

        try {
            // Buat nama file sementara unik
            $tempFileName = 'temp-ktp-' . uniqid() . '.' . $uploadedFile->getClientOriginalExtension();
            $tempPath = storage_path('app/' . $tempFileName);

            // Preprocessing gambar dengan sharpen
            Image::make($uploadedFile)
                ->greyscale()
                ->contrast(10)
                ->sharpen(15)
                ->resize(1200, null, function ($constraint) {
                    $constraint->aspectRatio();
                })
                ->save($tempPath);

            // Inisialisasi OCR
            $ocr = new TesseractOCR($tempPath);
            $ocr->lang('ind+eng'); // Gunakan bahasa Indonesia dan Inggris

            // Gunakan path custom jika disediakan di .env
            if (env('TESSERACT_EXECUTABLE_PATH')) {
                $ocr->executable(env('TESSERACT_EXECUTABLE_PATH'));
            }

            // Jalankan OCR
            $text = $ocr->run();

            // Ekstrak data dari teks
            $data = $this->extractDataFromText($text);

            return response()->json([
                'data' => $data,
                'raw_text' => $text,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to process KTP image.',
                'message' => $e->getMessage(),
            ], 500);

        } finally {
            // Hapus file sementara
            if ($tempPath && File::exists($tempPath)) {
                File::delete($tempPath);
            }
        }
    }

    /**
     * Ekstrak data penting dari hasil OCR.
     *
     * @param string $text
     * @return array
     */
    private function extractDataFromText(string $text): array
    {
        $lines = explode("\n", $text);
        $data = [
            'nik' => null,
            'nama' => null,
            'alamat' => null,
        ];

        foreach ($lines as $index => $line) {
            $cleanedLine = trim($line);

            // Tangkap NIK (tanpa harus ada kata "NIK")
            if (!$data['nik'] && preg_match('/\b\d{16}\b/', $cleanedLine, $matches)) {
                $data['nik'] = $matches[0];
                continue;
            }

            // Tangkap Nama
            if (!$data['nama'] && preg_match('/Nama\s*[:\-]?\s*(.+)/i', $cleanedLine, $matches)) {
                $data['nama'] = trim($matches[1]);
                continue;
            }

            // Tangkap Alamat
            if (!$data['alamat'] && preg_match('/Alamat\s*[:\-]?\s*(.+)/i', $cleanedLine, $matches)) {
                $alamat = trim($matches[1]);

                // Tambahkan baris berikutnya jika berisi RT/RW dsb
                if (isset($lines[$index + 1])) {
                    $nextLine = trim($lines[$index + 1]);
                    if (preg_match('/^(RT|RW|KEL|DESA|KEC|Kecamatan)/i', $nextLine)) {
                        $alamat .= ' ' . $nextLine;
                    }
                }

                $data['alamat'] = $alamat;
                continue;
            }
        }

        return $data;
    }
}
