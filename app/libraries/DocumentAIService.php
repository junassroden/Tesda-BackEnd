<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class DocumentAIService
{
    public function analyze_image($imagePath)
    {
        return $this->run([
            'mode' => 'image',
            'path' => $imagePath
        ]);
    }

    public function analyze_pdf($pdfPath)
    {
        return $this->run([
            'mode' => 'pdf',
            'path' => $pdfPath
        ]);
    }

    public function classify_text($text)
    {
        return $this->run([
            'mode' => 'text',
            'text' => $text
        ]);
    }

    private function run($payload)
    {
        $workerPath = ROOT_DIR . 'ai' . DIRECTORY_SEPARATOR . 'inference.py';
        if (!is_file($workerPath)) {
            throw new RuntimeException('The document AI worker is missing from the project.');
        }

        $python = $this->python_executable();
        $command = [$python, '-X', 'utf8', $workerPath];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $process = proc_open($command, $descriptors, $pipes, ROOT_DIR);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start Python. Configure DOCUMENT_AI_PYTHON for the portal.');
        }

        $request = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($request === false) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('The document analysis request could not be encoded.');
        }

        fwrite($pipes[0], $request);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $result = json_decode($output, true);
        if ($exitCode !== 0 || !is_array($result)) {
            error_log(
                'Document AI worker failed (exit ' . $exitCode . '): '
                . trim((string) $errorOutput)
                . ' Output: '
                . substr((string) $output, 0, 1000)
            );
            if (is_array($result) && !empty($result['error'])) {
                throw new RuntimeException($result['error']);
            }
            throw new RuntimeException(
                'Document analysis failed. Check that the Python dependencies and model files are installed.'
            );
        }

        return $result;
    }

    private function python_executable()
    {
        $configured = getenv('DOCUMENT_AI_PYTHON');
        if ($configured !== false && trim($configured) !== '') {
            return trim($configured);
        }

        $adjacentEnvironment = dirname(rtrim(ROOT_DIR, '\\/'))
            . DIRECTORY_SEPARATOR . 'AI'
            . DIRECTORY_SEPARATOR . '.venv'
            . DIRECTORY_SEPARATOR . 'Scripts'
            . DIRECTORY_SEPARATOR . 'python.exe';
        if (is_file($adjacentEnvironment)) {
            return $adjacentEnvironment;
        }

        return 'python';
    }
}
