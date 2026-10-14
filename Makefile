app_name  = backupdav
build_dir = $(CURDIR)/build
stage_dir = $(build_dir)/$(app_name)
sources   = appinfo lib README.md LICENSE

.PHONY: all build clean

all: build

build: clean
	mkdir -p $(stage_dir)
	cp -r $(sources) $(stage_dir)/
	tar -czf $(build_dir)/$(app_name).tar.gz -C $(build_dir) $(app_name)
	@echo "Built: $(build_dir)/$(app_name).tar.gz"

clean:
	rm -rf $(build_dir)
