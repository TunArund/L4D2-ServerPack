FROM ubuntu:22.04

# 使用阿里云 Ubuntu 镜像源
RUN sed -i 's/archive.ubuntu.com/mirrors.aliyun.com/g' /etc/apt/sources.list && \
    sed -i 's/security.ubuntu.com/mirrors.aliyun.com/g' /etc/apt/sources.list

# srcds 依赖（32位库 + 基础工具）
RUN dpkg --add-architecture i386 && \
    apt-get update && \
    apt-get install -y \
        ca-certificates \
        wget \
        curl \
        tar \
        bzip2 \
        procps \
        gdb \
        libc6:i386 \
        lib32z1 \
        lib32gcc-s1 \
    && apt-get clean

# entrypoint（运行时按 UID/GID 动态创建用户并降权）
COPY l4d2/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV HOME=/home/steam
WORKDIR /home/steam/l4d2

ENV L4D2_EXTRA_ARGS="+ip 0.0.0.0 -port 27015 \
    +sv_steam_bypass 1 +sv_setmax 32 \
    +map c1m1_hotel +z_difficulty normal \
    -tickrate 100 -high -heapsize 524288 \
    -num_edicts 4096 -processheap \
    +exec server.cfg"
ENTRYPOINT ["/entrypoint.sh"]

EXPOSE 27015/udp 27015/tcp
