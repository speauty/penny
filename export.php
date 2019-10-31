<?php
$link = mysqli_connect("","","", '');
if (!$link) die('Could not connect: ' . mysqli_error());
$sql = '';
$pre = mysqli_query($link, $sql);
$outputFileName = 'tmp.xlsx';
$excelObj = new \Vtiful\Kernel\Excel(['path'=>'./']);
$data = [];
while($row = $pre->fetch_array(MYSQLI_NUM)) {
    $row[0] && ($data[] = [$row[0]]);
}
mysqli_free_result($pre);
mysqli_close($link);
$outputObj = $excelObj->fileName($outputFileName, 'sheet1');
$outputPath = $outputObj->header(['header'])
    ->data($data)
    ->output();

