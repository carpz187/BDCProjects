<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\UserAccount;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return match ($request->session()->get('user_role')) {
            'admin' => redirect()->route('admin.dashboard'),
            'teacher' => redirect()->route('teacher.dashboard'),
            default => redirect()->route('student.dashboard'),
        };
    }

    public function admin()
    {
        return view('dashboards.admin', [
            'studentCount' => Student::count(),
            'teacherCount' => UserAccount::where('role', 'teacher')->count(),
        ]);
    }

    public function teacher()
    {
        return view('dashboards.teacher');
    }

    public function student(Request $request)
    {
        $student = Student::with('degree')
            ->where('user_account_id', $request->session()->get('user_account_id'))
            ->first();

        return view('dashboards.student', compact('student'));
    }

    public function exportPdf(Request $request): Response
    {
        $report = $this->dashboardReport($request);
        $pdf = $this->buildSimplePdf($report['title'], $report['rows']);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $report['filename'] . '.pdf"',
        ]);
    }

    public function exportExcel(Request $request): Response
    {
        $report = $this->dashboardReport($request);
        $xml = $this->buildExcelXml($report['title'], $report['rows']);

        return response($xml, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $report['filename'] . '.xls"',
        ]);
    }

    private function dashboardReport(Request $request): array
    {
        $role = $request->session()->get('user_role', 'student');
        $generatedAt = now()->format('M d, Y h:i A');

        if ($role === 'admin') {
            $studentCount = Student::count();
            $teacherCount = UserAccount::where('role', 'teacher')->count();

            return [
                'title' => 'BDC Admin Dashboard Report',
                'filename' => 'bdc-admin-dashboard-report',
                'rows' => [
                    ['Field', 'Value'],
                    ['Generated At', $generatedAt],
                    ['Students', $studentCount],
                    ['Teachers', $teacherCount],
                    ['Total Users', $studentCount + $teacherCount],
                    ['Access Level', 'Administrator'],
                ],
            ];
        }

        if ($role === 'teacher') {
            $teacher = UserAccount::find($request->session()->get('user_account_id'));

            return [
                'title' => 'BDC Teacher Dashboard Report',
                'filename' => 'bdc-teacher-dashboard-report',
                'rows' => [
                    ['Field', 'Value'],
                    ['Generated At', $generatedAt],
                    ['Username', $teacher->username ?? 'Teacher'],
                    ['Email', $teacher->email ?? 'No email'],
                    ['Access Level', 'Teacher'],
                    ['Session', 'Active'],
                    ['Protection', 'Enabled'],
                ],
            ];
        }

        $student = Student::with('degree')
            ->where('user_account_id', $request->session()->get('user_account_id'))
            ->first();

        return [
            'title' => 'BDC Student Dashboard Report',
            'filename' => 'bdc-student-dashboard-report',
            'rows' => [
                ['Field', 'Value'],
                ['Generated At', $generatedAt],
                ['Student ID', $student->student_id ?? 'No linked profile'],
                ['Name', $student ? trim($student->first_name . ' ' . $student->last_name) : 'No linked profile'],
                ['Email', $student->email ?? 'No linked profile'],
                ['Degree', $student->degree->degree_title ?? 'No linked profile'],
                ['Profile Status', $student ? 'Profile Linked' : 'Profile Pending'],
            ],
        ];
    }

    private function buildExcelXml(string $title, array $rows): string
    {
        $sheetRows = '';

        foreach ($rows as $row) {
            $sheetRows .= '<Row>';
            foreach ($row as $cell) {
                $sheetRows .= '<Cell><Data ss:Type="String">' . e((string) $cell) . '</Data></Cell>';
            }
            $sheetRows .= '</Row>';
        }

        return '<?xml version="1.0"?>' . "\n"
            . '<?mso-application progid="Excel.Sheet"?>' . "\n"
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            . 'xmlns:o="urn:schemas-microsoft-com:office:office" '
            . 'xmlns:x="urn:schemas-microsoft-com:office:excel" '
            . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . '<Worksheet ss:Name="' . e($title) . '"><Table>'
            . $sheetRows
            . '</Table></Worksheet></Workbook>';
    }

    private function buildSimplePdf(string $title, array $rows): string
    {
        $lines = [$title, ''];

        foreach ($rows as $row) {
            $lines[] = implode(': ', array_map(fn ($cell) => (string) $cell, $row));
        }

        $stream = "BT\n/F1 14 Tf\n50 780 Td\n";

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $stream .= "0 -22 Td\n";
            }

            $stream .= '(' . $this->escapePdfText($line) . ") Tj\n";
        }

        $stream .= "ET";

        return $this->assemblePdf($stream);
    }

    private function assemblePdf(string $stream): string
    {
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function escapePdfText(string $text): string
    {
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
