/**
 * 本地存储管理工具
 */

// 定义本地存储键名常量
export const LOCAL_STORAGE_KEYS = {
  AI_ANALYSIS_HISTORY: 'ai_analysis_history',
  USER_LOG_RECORDS: 'user_log_records',
  PAGE_TITLE: 'page_title'
};

// AI分析记录类型定义
export interface AIAnalysisRecord {
  id: string;
  logId: string;
  analysis: string;
  timestamp: Date;
}

// 用户日志记录类型定义
export interface UserLogRecord {
  id: string;
  title: string;
  timestamp: Date;
}

/**
 * 存储AI分析记录
 * @param logId 日志ID
 * @param analysis 分析结果
 */
export const saveAIAnalysisRecord = (logId: string, analysis: string): void => {
  try {
    const records: AIAnalysisRecord[] = JSON.parse(localStorage.getItem(LOCAL_STORAGE_KEYS.AI_ANALYSIS_HISTORY) || '[]');
    
    // 检查是否已有相同日志ID的记录，如果有则更新
    const existingIndex = records.findIndex(record => record.logId === logId);
    const newRecord: AIAnalysisRecord = {
      id: Date.now().toString(),
      logId,
      analysis,
      timestamp: new Date()
    };
    
    if (existingIndex !== -1) {
      records[existingIndex] = newRecord;
    } else {
      records.push(newRecord);
    }
    
    // 只保留最近的50条记录
    if (records.length > 50) {
      records.splice(0, records.length - 50);
    }
    
    localStorage.setItem(LOCAL_STORAGE_KEYS.AI_ANALYSIS_HISTORY, JSON.stringify(records));
  } catch (error) {
    console.error('保存AI分析记录失败:', error);
  }
};

/**
 * 获取特定日志的AI分析记录
 * @param logId 日志ID
 * @returns 分析记录数组
 */
export const getAIAnalysisRecords = (logId: string): AIAnalysisRecord[] => {
  try {
    const records: AIAnalysisRecord[] = JSON.parse(localStorage.getItem(LOCAL_STORAGE_KEYS.AI_ANALYSIS_HISTORY) || '[]');
    return records.filter(record => record.logId === logId);
  } catch (error) {
    console.error('获取AI分析记录失败:', error);
    return [];
  }
};

/**
 * 获取所有AI分析记录
 * @returns 所有分析记录
 */
export const getAllAIAnalysisRecords = (): AIAnalysisRecord[] => {
  try {
    return JSON.parse(localStorage.getItem(LOCAL_STORAGE_KEYS.AI_ANALYSIS_HISTORY) || '[]');
  } catch (error) {
    console.error('获取所有AI分析记录失败:', error);
    return [];
  }
};

/**
 * 删除特定日志的AI分析记录
 * @param logId 日志ID
 */
export const deleteAIAnalysisRecords = (logId: string): void => {
  try {
    const records: AIAnalysisRecord[] = JSON.parse(localStorage.getItem(LOCAL_STORAGE_KEYS.AI_ANALYSIS_HISTORY) || '[]');
    const filteredRecords = records.filter(record => record.logId !== logId);
    localStorage.setItem(LOCAL_STORAGE_KEYS.AI_ANALYSIS_HISTORY, JSON.stringify(filteredRecords));
  } catch (error) {
    console.error('删除AI分析记录失败:', error);
  }
};

/**
 * 保存用户日志记录
 * @param id 日志ID
 * @param title 日志标题
 */
export const saveUserLogRecord = (id: string, title: string): void => {
  try {
    const records: UserLogRecord[] = JSON.parse(localStorage.getItem(LOCAL_STORAGE_KEYS.USER_LOG_RECORDS) || '[]');
    
    // 检查是否已有相同ID的记录，如果有则更新
    const existingIndex = records.findIndex(record => record.id === id);
    const newRecord: UserLogRecord = {
      id,
      title,
      timestamp: new Date()
    };
    
    if (existingIndex !== -1) {
      records[existingIndex] = newRecord;
    } else {
      records.push(newRecord);
    }
    
    // 只保留最近的50条记录
    if (records.length > 50) {
      records.splice(0, records.length - 50);
    }
    
    localStorage.setItem(LOCAL_STORAGE_KEYS.USER_LOG_RECORDS, JSON.stringify(records));
  } catch (error) {
    console.error('保存用户日志记录失败:', error);
  }
};

/**
 * 获取所有用户日志记录
 * @returns 用户日志记录数组
 */
export const getUserLogRecords = (): UserLogRecord[] => {
  try {
    return JSON.parse(localStorage.getItem(LOCAL_STORAGE_KEYS.USER_LOG_RECORDS) || '[]');
  } catch (error) {
    console.error('获取用户日志记录失败:', error);
    return [];
  }
};

/**
 * 删除特定用户日志记录
 * @param id 日志ID
 */
export const deleteUserLogRecord = (id: string): void => {
  try {
    const records: UserLogRecord[] = JSON.parse(localStorage.getItem(LOCAL_STORAGE_KEYS.USER_LOG_RECORDS) || '[]');
    const filteredRecords = records.filter(record => record.id !== id);
    localStorage.setItem(LOCAL_STORAGE_KEYS.USER_LOG_RECORDS, JSON.stringify(filteredRecords));
  } catch (error) {
    console.error('删除用户日志记录失败:', error);
  }
};

/**
 * 清空所有本地存储数据
 */
export const clearAllLocalStorageData = (): void => {
  try {
    localStorage.removeItem(LOCAL_STORAGE_KEYS.AI_ANALYSIS_HISTORY);
    localStorage.removeItem(LOCAL_STORAGE_KEYS.USER_LOG_RECORDS);
  } catch (error) {
    console.error('清空本地存储数据失败:', error);
  }
};