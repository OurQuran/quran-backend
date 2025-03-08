<?php

function extractModelAndIdFromNotFoundMessage($message)
{
    // Extract the part after "App\\Models\\"
    $modelStart = strpos($message, 'App\\Models\\');
    if ($modelStart === false) {
        return null;
    }

    // Find the start and end of the model name
    $modelStart += strlen('App\\Models\\');
    $modelEnd = strpos($message, ']', $modelStart);
    if ($modelEnd === false) {
        return null;
    }

    // Extract model name
    $model = substr($message, $modelStart, $modelEnd - $modelStart);

    // Extract the ID, assuming it's the last word in the message
    $parts = explode(' ', $message);
    $id = end($parts);

    return [$model, $id];
}


function convertFromPascalCaseToNormalCase($string)
{
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $string));
}
