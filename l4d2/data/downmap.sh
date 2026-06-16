#!/bin/bash

urls='urls.txt'
force_overwrite=0

# 解析参数
while getopts "f" opt; do
  case $opt in
    f)
      force_overwrite=1
      ;;
    *)
      echo "Usage: $0 [-f]"
      exit 1
      ;;
  esac
done

dos2unix "$urls"
mkdir -p workshop

# 创建临时文件用于更新状态
temp_file=$(mktemp)

while IFS= read -r line; do
  # 检查是否已标记 done
  if [[ "$line" =~ done$ ]]; then
    echo "$line" >> "$temp_file"
    continue
  fi

  # 解析 name 和 url
  name=$(echo "$line" | awk '{print $1}')
  url=$(echo "$line" | awk '{print $2}')
  filepath="workshop/${name}"

  # 下载逻辑
  if [[ $force_overwrite -eq 1 ]]; then
    echo "Downloading (overwrite): $url -> $filepath"
    wget -O "$filepath" "$url"
  else
    echo "Downloading (continue): $url -> $filepath"
    wget -O "$filepath" -c "$url"
  fi

  # 检查 wget 下载是否成功
  if [[ $? -eq 0 ]]; then
    echo "$name $url done" >> "$temp_file"
  else
    echo "$name $url" >> "$temp_file"
    echo "❌ Failed to download: $name ($url)"
  fi
done < "$urls"

# 替换原文件
mv "$temp_file" "$urls"
