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
            $image = Image::make($uploadedFile)
                ->greyscale()
                ->contrast(10)
                ->sharpen(15)
                ->blur(1) // Added for noise reduction
                ->resize(1200, null, function ($constraint) {
                    $constraint->aspectRatio();
                });

            // Replicate cv2.THRESH_TRUNC (if pixel intensity > 127, set to 127)
            // WARNING: This pixel-by-pixel operation can be slow for large images.
            $width = $image->width();
            $height = $image->height();
            $threshold = 127;

            for ($x = 0; $x < $width; $x++) {
                for ($y = 0; $y < $height; $y++) {
                    $color = $image->pickColor($x, $y); // Returns an array [R, G, B, A]
                    $intensity = $color[0]; // For greyscale, R, G, B are the same.

                    if ($intensity > $threshold) {
                        // Set pixel to the threshold value (127)
                        $image->pixel([$threshold, $threshold, $threshold], $x, $y);
                    }
                }
            }

            $image->save($tempPath);

            // Inisialisasi OCR
            $ocr = new TesseractOCR($tempPath);
            $ocr->lang('ind'); // Gunakan bahasa Indonesia ('ind' for Tesseract)
            $ocr->psm(6); // Set Page Segmentation Mode to 6 (single uniform block of text)
            $ocr->oem(1); // Use LSTM engine for better accuracy (requires Tesseract 4+)

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
        $text = $this->normalizeText($text); // Normalize the raw text

        $lines = explode("\n", $text);
        $data = [
            'nik' => null,
            'nama' => null,
            'tempat_tgl_lahir' => null,
            'jenis_kelamin' => null,
            'gol_darah' => null,
            'alamat' => null,
            'rt_rw' => null,
            'kel_desa' => null,
            'kecamatan' => null,
            'agama' => null,
            'status_perkawinan' => null,
            'pekerjaan' => null,
            'kewarganegaraan' => null,
            'berlaku_hingga' => null,
        ];

        foreach ($lines as $index => $line) {
            $cleanedLine = trim($line);

            // NIK
            if (!$data['nik'] && preg_match('/(\d{16})/', $cleanedLine, $matches)) {
                if (strlen($matches[1]) === 16) { // Basic NIK length validation
                    $data['nik'] = $matches[1];
                }
                continue;
            }

            // Nama
            if (!$data['nama'] && preg_match('/Nama\s*[:\-\s]*([A-Z\s\.]+)/i', $cleanedLine, $matches)) {
                $data['nama'] = trim($matches[1]);
                continue;
            }

            // Tempat/Tgl Lahir
            if (!$data['tempat_tgl_lahir'] && preg_match('/Tempat\/\s*Tgl\s*Lahir\s*[:\-\s]*([A-Z\s\.,\d\-\/]+)/i', $cleanedLine, $matches)) {
                $data['tempat_tgl_lahir'] = trim($matches[1]);
                continue;
            }
            // Alternative pattern for Tempat/Tgl Lahir
            if (!$data['tempat_tgl_lahir'] && preg_match('/([A-Z][a-z]+(?: [A-Z][a-z]+)*)\s*,\s*(\d{2}-\d{2}-\d{4})/i', $cleanedLine, $matches)) {
                $data['tempat_tgl_lahir'] = trim($matches[0]);
                continue;
            }


            // Jenis Kelamin
            if (!$data['jenis_kelamin'] && preg_match('/Jenis\s*Kelamin\s*[:\-\s]*(LAKI-LAKI|PEREMPUAN)\s*(?:Gol\.\s*Darah)?/i', $cleanedLine, $matches)) {
                $data['jenis_kelamin'] = trim($matches[1]);
                continue;
            }

            // Gol. Darah (often appears on the same line as Jenis Kelamin or nearby)
            if (!$data['gol_darah'] && preg_match('/Gol\.\s*Darah\s*[:\-\s]*(A|B|AB|O)/i', $cleanedLine, $matches)) {
                $data['gol_darah'] = trim($matches[1]);
                continue;
            }

            // Alamat
            if (!$data['alamat'] && preg_match('/Alamat\s*[:\-\s]*(.+)/i', $cleanedLine, $matches)) {
                $data['alamat'] = trim($matches[1]);
                // Check for subsequent lines for RT/RW, Kel/Desa, Kecamatan
                $tempAddress = $data['alamat'];
                for ($i = $index + 1; $i < count($lines); $i++) {
                    $subLine = trim($lines[$i]);
                    // Only continue if the next line starts with an address component keyword
                    if (preg_match('/^(RT|RW|KEL|DESA|KEC|Kecamatan)\s*[:\-\s]*(.+)/i', $subLine, $subMatches)) {
                        $tempAddress .= ' ' . trim($subMatches[0]); // Append the whole matched part
                        // Extract specific parts if needed for separate fields
                        if (preg_match('/RT\/RW\s*[:\-\s]*(\d{3}\/\d{3})/i', $subLine, $rtRwMatches)) {
                            $data['rt_rw'] = $rtRwMatches[1];
                        }
                        if (preg_match('/(KEL|DESA)\s*[:\-\s]*(.+)/i', $subLine, $kelDesaMatches)) {
                            $data['kel_desa'] = trim($kelDesaMatches[2]);
                        }
                        if (preg_match('/(KEC|Kecamatan)\s*[:\-\s]*(.+)/i', $subLine, $kecamatanMatches)) {
                            $data['kecamatan'] = trim($kecamatanMatches[2]);
                        }
                    } else {
                        // Stop if the next line doesn't seem to be part of the address
                        break;
                    }
                }
                $data['alamat'] = $tempAddress; // Update full address
                continue;
            }

            // Agama
            if (!$data['agama'] && preg_match('/Agama\s*[:\-\s]*(ISLAM|KRISTEN|KATOLIK|HINDU|BUDHA|KONGHUCU)/i', $cleanedLine, $matches)) {
                $data['agama'] = trim($matches[1]);
                continue;
            }

            // Status Perkawinan
            if (!$data['status_perkawinan'] && preg_match('/Status\s*Perkawinan\s*[:\-\s]*(BELUM KAWIN|KAWIN|CERAI HIDUP|CERAI MATI)/i', $cleanedLine, $matches)) {
                $data['status_perkawinan'] = trim($matches[1]);
                continue;
            }

            // Pekerjaan
            if (!$data['pekerjaan'] && preg_match('/Pekerjaan\s*[:\-\s]*(.+)/i', $cleanedLine, $matches)) {
                $data['pekerjaan'] = trim($matches[1]);
                continue;
            }

            // Kewarganegaraan
            if (!$data['kewarganegaraan'] && preg_match('/Kewarganegaraan\s*[:\-\s]*(WNI|WNA)/i', $cleanedLine, $matches)) {
                $data['kewarganegaraan'] = trim($matches[1]);
                continue;
            }

            // Berlaku Hingga
            if (!$data['berlaku_hingga'] && preg_match('/Berlaku\s*Hingga\s*[:\-\s]*(\d{2}-\d{2}-\d{4}|SEUMUR HIDUP)/i', $cleanedLine, $matches)) {
                $data['berlaku_hingga'] = trim($matches[1]);
                continue;
            }
        }

        return $data;
    }

    /**
     * Normalize the raw text output from OCR.
     * Removes extra spaces and trims each line.
     *
     * @param string $text
     * @return string
     */
    private function normalizeText(string $text): string
    {
        // Replace multiple whitespace characters with a single space
        $text = preg_replace('/\s+/', ' ', $text);
        // Trim leading/trailing whitespace from the entire text
        $text = trim($text);
        return $text;
    }
}