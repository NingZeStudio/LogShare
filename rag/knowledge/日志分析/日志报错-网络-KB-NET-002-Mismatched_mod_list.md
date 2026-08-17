========
java.io.IOException: Mismatched mod list — 模组列表不匹配
========
报错堆栈：
java.io.IOException: Mismatched mod list
场景：加入服务器时被踢出
出现的可能原因：
客户端与服务端的模组列表不一致
解决方案：
对比两端模组列表，确保模组 ID 和版本完全一致（允许客户端多装非服务端模组，但不允许缺少服务端模组或版本不匹配）