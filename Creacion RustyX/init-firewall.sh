#!/bin/sh
set -e

apk add --no-cache iptables iproute2 >/dev/null

sysctl -w net.ipv4.ip_forward=1 >/dev/null

iptables -F
iptables -X
iptables -t nat -F
iptables -t nat -X
iptables -P INPUT DROP
iptables -P FORWARD DROP
iptables -P OUTPUT ACCEPT

iptables -A INPUT -i lo -j ACCEPT
iptables -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
iptables -A FORWARD -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

iptables -A INPUT -s 192.168.10.0/24 -j ACCEPT
iptables -A INPUT -s 192.168.20.0/24 -j ACCEPT
iptables -A INPUT -s 192.168.30.0/24 -j ACCEPT

iptables -A FORWARD -s 192.168.10.0/24 -d 192.168.10.10 -p tcp -m multiport --dports 80,443 -j ACCEPT
iptables -A FORWARD -s 192.168.20.2 -d 192.168.20.10 -p tcp -m multiport --dports 80,443 -j ACCEPT
iptables -A FORWARD -s 192.168.20.2 -d 192.168.20.11 -p tcp -m multiport --dports 80,443 -j ACCEPT
iptables -A FORWARD -s 192.168.20.0/24 -d 192.168.30.10 -p tcp --dport 3306 -j ACCEPT

exec tail -f /dev/null