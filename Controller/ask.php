<?php
/**
 * ask.php
 * ------------------------------------------------------------
 * 前端 React 继续 POST 到这个文件，接口路径和行为不变。
 * 原本塞在这里的 controller + logic + mock data 已经拆分到
 * ChatbotController / ChatbotLogic / GeminiService / StudyMaterialRepository。
 * ------------------------------------------------------------
 */
//1
require_once __DIR__ . '/ChatbotController.php';

handleAskRequest();