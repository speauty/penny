<?php
use Swoole\Http\Server;

$http = new Server("127.0.0.1", 9987);
$http->on('request', function ($request, $response) {
    try {
        $file = $request->files['xlsx'];
        if (!$file || $file['size'] < 10) throw new TypeError('the file not found in this server');
        $realFileName = $file['name'];
        $fileExt = pathinfo($file['name'],PATHINFO_EXTENSION);
        if ($fileExt !== 'xlsx') throw new TypeError('this can only support xlsx file');
        unset($fileExt);
        if (!extension_loaded('xlswriter')) throw new \Exception('the extend named xlswrite not load, please check it');
        $tmpFile = $file['tmp_name'];
        unset($file);
        $tmpArr = explode('/', $tmpFile);
        $tmpName = array_pop($tmpArr);
        $tmpDir = '/'.implode('/', $tmpArr);
        unset($tmpArr);
        $excelObj = new \Vtiful\Kernel\Excel(['path'=>$tmpDir]);
        $excelObj->openFile($tmpName)->openSheet();
        $date = [];
        $excelObj->nextRow();
        while($row = $excelObj->nextRow()) {
            $v = [
                'date' => date('Y-m-d', strtotime(trim($row[1]))),
                'temperature' => round($row[2], 2),
                'humidity' => round($row[5], 2)
            ];
            if (isset($date[$v['date']])) {
                if ($v['temperature'] > $date[$v['date']]['temp_max']) {
                    $date[$v['date']]['temp_max']=  $v['temperature'];
                } else if ($v['temperature'] < $date[$v['date']]['temp_min']) {
                    $date[$v['date']]['temp_min']=  $v['temperature'];
                }

                if ($v['humidity'] > $date[$v['date']]['humidity_max']) {
                    $date[$v['date']]['humidity_max']=  $v['humidity'];
                } else if ($v['humidity'] < $date[$v['date']]['humidity_min']) {
                    $date[$v['date']]['humidity_min']=  $v['humidity'];
                }
            } else {
                $date[$v['date']] = [
                    'date' => $v['date'],
                    'temp_min' => $v['temperature'],
                    'temp_max' => $v['temperature'],
                    'humidity_min' => $v['humidity'],
                    'humidity_max' => $v['humidity']
                ];
            }
            unset($v);
        }
        $data = [];
        foreach ($date as $v) $data[] = array_values($v);
        unset($date);
        $outputFileName = '统计-'.$realFileName;
        $outputObj = $excelObj->fileName($outputFileName);
        $outputPath = $outputObj->header(['日期', '最低温度', '最高温度', '最低湿度', '最高湿度'])
            ->data($data)
            ->output();
        $response->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->header('Content-Disposition', 'attachment;filename="' . $outputFileName . '"');
        $response->header('Cache-Control', 'max-age=0');
        $response->sendfile($outputPath);
    } catch (\Throwable $e) {
        $response->end('program broken, code:'.$e->getCode().' msg:'.$e->getMessage());
    }
});
$http->start();